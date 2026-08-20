<?php

namespace App\Domain\Signatures;

use App\Models\Document;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use Illuminate\Support\Collection;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * The audit certificate appended to an executed document.  [FR-SIG-02 step 6, AC-SIG-02]
 *
 * Element 5 of the ESIGN evidence chain, and the one a court actually reads.
 * It carries the document's name, version and hash, the signer's identity, the
 * exact button they pressed, the consent they had recorded, and the full event
 * log with millisecond timestamps and IP addresses.
 *
 * **The original bytes are never re-encoded.** The pages are imported and
 * placed verbatim, because the SHA-256 recorded at signing is of the source
 * file: re-rendering it would produce a document whose hash no longer matches
 * the thing that was signed, which is the one failure this whole feature
 * exists to prevent (BR-26).
 *
 * FPDI cannot parse every PDF — files using cross-reference streams, which
 * newer Word and Acrobat output often do, need a parser that is not free. When
 * import fails the certificate is still produced, standing alone and naming the
 * original by hash, and the caller is told so it can be surfaced rather than
 * discovered. A cryptographic binding is not a worse binding than a physical
 * one; a silent one would be.
 */
class AuditCertificate
{
    /** @var array{merged: bool} */
    private array $lastOutcome = ['merged' => false];

    /**
     * @param  Collection<int, SignatureEvent>  $events
     * @param  array<string, mixed>  $consent
     * @return string the executed PDF bytes
     */
    public function build(
        SignatureRequest $request,
        Document $document,
        string $sourcePath,
        Collection $events,
        array $consent,
    ): string {
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(true, 15);
        $merged = false;

        try {
            $pages = $pdf->setSourceFile($sourcePath);

            for ($page = 1; $page <= $pages; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                // Placed verbatim. Nothing is re-rendered, so the bytes the
                // reader sees are the bytes that were hashed.
                $pdf->useTemplate($template);
            }

            $merged = true;
        } catch (Throwable $e) {
            // Reported through lastOutcome(), never swallowed. See the class
            // comment: an unmergeable source is a known boundary, not a bug.
            $pdf = new Fpdi;
            $pdf->SetAutoPageBreak(true, 15);
        }

        $this->lastOutcome = ['merged' => $merged];

        $this->certificatePage($pdf, $request, $document, $events, $consent, $merged);

        return $pdf->Output('S');
    }

    /** @return array{merged: bool} whether the original pages were embedded */
    public function lastOutcome(): array
    {
        return $this->lastOutcome;
    }

    /**
     * @param  Collection<int, SignatureEvent>  $events
     * @param  array<string, mixed>  $consent
     */
    private function certificatePage(
        Fpdi $pdf,
        SignatureRequest $request,
        Document $document,
        Collection $events,
        array $consent,
        bool $merged,
    ): void {
        $pdf->AddPage();

        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->Cell(0, 9, 'Certificate of electronic signature', 0, 1);

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90);
        $pdf->MultiCell(0, 4.5, $this->preamble($merged));
        $pdf->SetTextColor(0);
        $pdf->Ln(3);

        $signed = $events->firstWhere('event', SignatureEvent::SIGNED);

        $this->section($pdf, 'The document');
        $this->row($pdf, 'Title', (string) $document->title);
        $this->row($pdf, 'Filename', (string) $document->original_filename);
        $this->row($pdf, 'Version', (string) $document->version);
        // Element 4 of the chain. Any later mismatch invalidates the signature.
        $this->row($pdf, 'SHA-256 of the signed bytes', (string) ($signed?->document_sha256 ?? $document->sha256));

        $this->section($pdf, 'The signer');
        $this->row($pdf, 'Name on file', (string) $request->signer?->name);
        $this->row($pdf, 'Email', (string) $request->signer?->email);
        // Element 2: intent. The typed name and the exact control they pressed.
        $this->row($pdf, 'Typed name', (string) ($signed?->typed_name ?? '—'));
        $this->row($pdf, 'Control used', (string) ($signed?->button_label ?? '—'));
        $this->row($pdf, 'Signed at (UTC)', $signed?->occurred_at?->format('Y-m-d H:i:s.v') ?? '—');
        $this->row($pdf, 'IP address', (string) ($signed?->ip_address ?? '—'));

        $this->section($pdf, 'Consent to electronic records');
        $this->row($pdf, 'Agreed at (UTC)', (string) ($consent['agreed_at'] ?? '—'));
        $this->row($pdf, 'Text version', (string) ($consent['version'] ?? '—'));
        $this->row($pdf, 'SHA-256 of consent text', (string) ($consent['sha256'] ?? '—'));
        $this->row($pdf, 'IP address', (string) ($consent['ip_address'] ?? '—'));

        $this->section($pdf, 'Event log');

        $pdf->SetFont('Courier', '', 7.5);
        foreach ($events as $event) {
            $pdf->MultiCell(0, 3.8, sprintf(
                '%s  %-18s %s',
                $event->occurred_at?->format('Y-m-d H:i:s.v') ?? '',
                $event->event,
                trim(($event->ip_address ?? '').'  '.substr((string) $event->user_agent, 0, 70)),
            ));
        }

        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(90);
        $pdf->MultiCell(0, 4, 'Produced by '.config('app.name').' at '.now()->format('Y-m-d H:i:s').' UTC. '
            .'This certificate is part of the executed document and is retained with it.');
    }

    private function preamble(bool $merged): string
    {
        $base = 'This certificate records an electronic signature made under the Electronic '
            ."Signatures in Global and National Commerce Act and the Uniform Electronic \n"
            .'Transactions Act. It lists the signer, the moment of signing, the network address '
            .'used, and a cryptographic fingerprint of the exact document presented.';

        if ($merged) {
            return $base."\n".'The signed document appears on the preceding pages, unaltered.';
        }

        // Said plainly on the face of the certificate rather than left to a
        // support conversation later.
        return $base."\n".'The signed document could not be embedded in this file and is retained '
            .'separately in the document vault. It is identified by the SHA-256 fingerprint below, '
            .'which was computed from the exact bytes presented to the signer.';
    }

    private function section(Fpdi $pdf, string $title): void
    {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, $title, 0, 1);
        $pdf->SetDrawColor(200);
        $pdf->Cell(0, 0, '', 'T', 1);
        $pdf->Ln(1);
    }

    private function row(Fpdi $pdf, string $label, string $value): void
    {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(58, 5, $label, 0, 0);
        // Courier for hashes and addresses: a fingerprint someone may have to
        // compare by eye should be monospaced.
        $pdf->SetFont(strlen($value) === 64 || filter_var($value, FILTER_VALIDATE_IP) ? 'Courier' : 'Helvetica', '', 9);
        $pdf->MultiCell(0, 5, $value);
    }
}
