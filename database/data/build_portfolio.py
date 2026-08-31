# -*- coding: utf-8 -*-
"""
Build `portfolio.json` — the real Heads Up Enterprises portfolio.

Two sources, and they do not overlap:

  Portfolio.xlsx     current money: contract rent, HAP portion, tenant portion,
                     bed/bath, parcel, owner, and the lease renewal date.
  27 lease PDFs      original tenancy terms: start date, rent due day, grace
                     period, the late-charge rule, and the security deposit.

The PDFs are scans with no text layer, so their values were read visually and
are held in PDF/PDF_END/UTILITIES below. That hand-keyed block is the only part
of this pipeline with no second source behind it — it is kept in version
control for exactly that reason. Page images can be regenerated with PyMuPDF
from the lease PDFs if a figure ever needs re-checking.

Money is emitted as decimal STRINGS. App\\Casts\\MoneyCast throws on a float
(invariant I-10), so a float must never reach the seeder.

    python database/data/build_portfolio.py
"""
import json, datetime as dt, collections, openpyxl
from decimal import Decimal as D
from pathlib import Path

HERE = Path(__file__).resolve().parent
XL = HERE.parent.parent.parent / 'Portfolio-20260831T163540Z-1-001' / 'Portfolio' / 'Portfolio.xlsx'

# ---------------------------------------------------------------------------
# Read from the signed leases (scans — keyed by hand, see module docstring).
# key -> (start_date, security_deposit, first, last, household_size, address_as_printed)
# ---------------------------------------------------------------------------
PDF = {
 '0968': ('2026-04-30', '800.00',  'Shenterlyn', 'Sweet',    2, '968 PONCE DE LEON CIR S'),
 '1349': ('2025-05-06', '900.00',  'Wanda',      'Augustave',3, '1349 Peavy Dr'),
 '1352': ('2022-09-12', '500.00',  'Verlyn',     'Carter',   1, '1352 ROCKY CREEK RD'),
 '1354': ('2025-08-04', '1000.00', 'Travolta',   'Moore',    1, '1354 Rocky Creek Rd'),
 '1356': ('2021-07-28', '400.00',  'Dishawanda', 'Sherman',  1, '1356 ROCKY CREEK RD'),
 '1358': ('2023-07-12', '600.00',  'James',      'Green',    1, '1358 Rocky Creek Rd'),
 '1580': ('2011-12-06', '500.00',  'Dania',      'Jackson',  4, '1580 Hurley Circle'),
 '1591': ('2024-04-08', '900.00',  'Janay',      'Banks',    6, '1591 HURLEY CIR'),
 '1657': ('2026-02-24', '1000.00', 'Alvina',     'Chatman',  3, '1657 RANDALL RD'),
 '2415': ('2024-08-23', '1000.00', 'Brian',      'Glover',   3, '2415 CHARLENE TERRACE'),
 '2427': ('2023-03-07', '700.00',  'Regina',     'McDowell', 2, '2427 BREVARD DR'),
 '2428': ('2014-04-18', '500.00',  'Sherrie',    'Browner',  3, '2428 BREVARD DR'),
 '2432': ('2026-04-08', '1000.00', 'Jasmine',    'Haynes',   2, '2432 ADGER RD'),
 '2448': ('2018-11-29', '600.00',  'Elaine',     'Askew',    1, '2448 Rocky Creek Rd'),
 '2456': ('2024-12-09', '900.00',  'Jamie',      'Major',    3, '2456 GROVELAND CIR S'),
 '2464': ('2023-06-22', '900.00',  'Otisha',     'Farrar',   5, '2464 ROCKY CREEK RD'),
 '2470': ('2022-05-04', '700.00',  'Angela',     'Parks',    2, '2470 Rocky Creek Rd'),
 '2472': ('2007-08-01', '400.00',  'Camille',    'Troupe',   3, '2472 HOLLAND DRIVE'),
 '2482': ('2022-06-13', '800.00',  'Towanna',    'Calloway', 3, '2482 HOLLAND DRIVE'),
 '2641': ('2026-06-29', '1000.00', 'Veronica',   'Cooper',   2, '2641 VIRGINIA AVE'),
 '2869': ('2008-04-10', '300.00',  'Audrea',     'Lewis',    4, '2869 BARNETT AVE'),
 '2974': ('2021-08-06', '600.00',  'Renita',     'Danielly', 4, '2974 BLOOMFIELD DR'),
 '3327': ('2017-05-09', '500.00',  'Rosa',       'Colbert',  2, '3327 Tamplin Ter'),
 '3595': ('2014-05-05', '200.00',  'Erica',      'Howard',   1, '3595 PLYMOUTH DR'),
 '3730': ('2024-01-01', '900.00',  'Laquandria', 'Shelly',   5, '3730 SPENCER CIR'),
 '3990': ('2018-12-31', '600.00',  'Tavares',    'Pearson',  1, '3990 Spencer Cir'),
 '4424': ('2018-12-03', '600.00',  'Shamiqua',   'Hubbard',  3, '4424 Elkan Ave'),
}

# Initial-term end date as printed on each lease. Only 4 of 27 still match the
# spreadsheet's renewal date — the rest have rolled over since signing.
PDF_END = {
 '0968':'2027-03-31','1349':'2026-04-30','1352':'2023-08-31','1354':'2026-07-31','1356':'2022-06-30',
 '1358':'2024-06-30','1580':'2014-11-30','1591':'2025-03-31','1657':'2027-01-31','2415':'2025-07-31',
 '2427':'2024-02-29','2428':'2015-03-30','2432':'2027-03-31','2448':'2019-10-31','2456':'2025-11-30',
 '2464':'2024-05-30','2470':'2023-04-30','2472':'2008-07-31','2482':'2023-05-31','2641':'2027-05-31',
 '2869':'2009-03-31','2974':'2022-07-31','3327':'2018-04-30','3595':'2015-04-30','3730':'2024-12-31',
 '3990':'2019-11-30','4424':'2019-11-30',
}

# Only the three leases whose utility table was actually read. The rest stay
# NULL rather than being filled in by assumption.
UTILITIES = {
 '1352': 'Tenant pays electric heating, cooking, water heating, other electric, trash and air conditioning. '
         'Owner pays water and sewer, and provides the air conditioner, range and refrigerator.',
 '1354': 'Tenant arranges and pays all utilities: electricity, water, natural gas, cable, telephone and garbage.',
 '2472': 'Tenant pays heat-pump heating, electric cooking, other electric, water heating, water, sewer and trash. '
         'Owner provides the air conditioner, range and refrigerator.',
}

# Street address per unit key. Taken from the spreadsheet (the current
# operational record); where the signed lease spells it differently the lease
# variant is recorded in the property notes instead of silently overwriting.
STREET = {
 '0968':'968 Ponce de Leon Cir S','1349':'1349 Peavy Dr','1352':'1352 Rocky Creek Rd','1354':'1354 Rocky Creek Rd',
 '1356':'1356 Rocky Creek Rd','1358':'1358 Rocky Creek Rd','1580':'1580 Hurley Cir','1591':'1591 Hurley Cir',
 '1657':'1657 Randall Rd','2415':'2415 Charlene Ter','2427':'2427 Brevard Dr','2428':'2428 Brevard Dr',
 '2432':'2432 Adger Rd','2448':'2448 Rocky Creek Rd','2456':'2456 Groveland Cir S','2464':'2464 Rocky Creek Rd',
 '2470':'2470 Rocky Creek Rd','2472':'2472 Holland Dr','2482':'2482 Holland Dr','2641':'2641 Virginia Dr',
 '2869':'2869 Barrett Ave','2974':'2974 Bloomfield Dr','3327':'3327 Tamplin Ter','3595':'3595 Plymouth Dr',
 '3730':'3730 Spencer Cir','3990':'3990 Spencer Cir','4424':'4424 Elkan Ave',
}

# Where the lease disagrees with the spreadsheet about the street name.
ADDRESS_CONFLICT = {
 '2641': 'Signed lease reads "2641 VIRGINIA AVE"; portfolio sheet reads "Virginia Drive". Confirm with client.',
 '2869': 'Signed lease reads "2869 BARNETT AVE"; portfolio sheet and lease filename read "Barrett Ave". Confirm with client.',
}

# The two duplexes: unit key -> (property name, unit number).
DUPLEX = {
 '1352': ('1352-1354 Rocky Creek Rd', '1352'), '1354': ('1352-1354 Rocky Creek Rd', '1354'),
 '1356': ('1356-1358 Rocky Creek Rd', '1356'), '1358': ('1356-1358 Rocky Creek Rd', '1358'),
}

POSTAL = collections.defaultdict(lambda: '31206', {'3595': '31204'})


def money(v):
    return None if v is None else str(D(str(v)).quantize(D('0.01')))


def main():
    ws = openpyxl.load_workbook(XL, data_only=True)['Sheet1']

    props, records, notes = {}, [], []
    for r in range(4, 31):
        addr = str(ws.cell(r, 1).value).strip()
        k = addr.split()[0]
        p = PDF[k]

        total, hap, ten = ws.cell(r, 12).value, ws.cell(r, 13).value, ws.cell(r, 14).value
        subsid = not (ws.cell(r, 17).value and 'not section 8' in str(ws.cell(r, 17).value).lower())
        if k == '2641':                      # vacant in the sheet; money from the Cooper lease
            total, hap, ten, subsid = 1083.0, 564.0, 519.0, True
        hap = hap or 0.0
        ten = (total or 0) - hap if ten is None else ten

        # end_date = the sheet's renewal date minus one day. Verified against
        # four leases whose printed end date is exactly renewal - 1 day.
        rv, end = ws.cell(r, 16).value, None
        if isinstance(rv, dt.datetime):
            end, src = (rv.date() - dt.timedelta(days=1)).isoformat(), 'sheet renewal minus 1 day'
        elif k == '2641':
            end, src = PDF_END[k], 'lease PDF (sheet blank)'
        else:                                # 2472 Holland: sheet says "month to month"
            s = dt.date.fromisoformat(p[0])
            end = (dt.date(2027, s.month, 1) - dt.timedelta(days=1)).isoformat()
            src = 'ASSUMED next anniversary (month-to-month, no date in either source)'

        pname, unum = DUPLEX.get(k, (STREET[k], '1'))
        street = STREET[k] if k not in DUPLEX else pname

        if pname not in props:
            bits = [f"Parcel {ws.cell(r,2).value or 'not recorded'}",
                    f"Owner {ws.cell(r,3).value or 'not recorded'}",
                    f"{ws.cell(r,4).value}"]
            if ws.cell(r, 8).value:
                bits.append(f"{int(ws.cell(r,8).value)} sq ft")
            if k in ADDRESS_CONFLICT:
                bits.append(ADDRESS_CONFLICT[k])
            props[pname] = dict(name=pname, country_code='US', street_address=street,
                                address_line_2=None, city='Macon', state='Georgia',
                                postal_code=POSTAL[k], county='Bibb', notes='. '.join(bits) + '.')

        market = k == '1354'
        records.append(dict(
            key=k, property=pname,
            unit=dict(unit_number=unum,
                      bedrooms=int(ws.cell(r, 9).value) if ws.cell(r, 9).value else None,
                      bathrooms=str(D(str(ws.cell(r, 10).value)).quantize(D('0.1'))) if ws.cell(r, 10).value else None,
                      status='vacant'),           # LeaseService flips this to occupied
            tenant=dict(first_name=p[2], last_name=p[3], email=None, phone=None,
                        emergency_contact_name=None, emergency_contact_phone=None,
                        status='active',
                        notes=f'Household of {p[4]} per the signed lease. '
                              f'No email or phone in either source — no portal invite until supplied (Q-4).'),
            lease=dict(
                start_date=p[0], end_date=end,
                total_contract_rent=money(total), tenant_portion=money(ten), ha_portion=money(hap),
                rent_due_day=1,
                grace_period_days=4 if market else 5,
                # [GAP] The Section 8 leases charge 10% of the tenant portion with
                # a $15 floor. `leases` has no percentage column, so only the floor
                # can be stored. Correct for the 12 tenants whose portion is $0 and
                # an under-charge for the rest — see portfolio.json._meta.
                late_fee_flat='50.00' if market else '15.00',
                late_fee_daily='0.00', late_fee_max=None,
                returned_payment_fee='25.00' if market else '0.00',
                security_deposit=p[1],
                is_subsidised=subsid,
                hap_contract_number=None,
                utility_responsibility=UTILITIES.get(k),
                partial_payment_policy='full_only',      # [GATE] client decision
                partial_minimum_amount=None, partial_policy_expires_on=None,
                partial_requires_approval=True, ledger_review_required=False,
                delinquency_state='current', status='active',
            ),
            provenance=dict(end_date_source=src, lease_initial_end=PDF_END[k],
                            deposit_source='signed lease',
                            deposit_in_sheet=money(ws.cell(r, 15).value),
                            lease_address=p[5],
                            template='market rate (Macon4rent contract)' if market
                                     else 'Section 8 (Bibb County Dwelling Lease)'),
        ))

    doc = dict(
        _meta=dict(
            generated_by='database/data/build_portfolio.py',
            sources=['Portfolio.xlsx (current money)', '27 signed lease PDFs (original terms)'],
            properties=len(props), units=len(records), tenants=len(records), leases=len(records),
            known_issues=[
                'LATE FEE: 26 of 27 leases charge 10% of the tenant portion with a $15 floor. '
                'The leases table has no percentage column, so late_fee_flat holds the $15 floor only. '
                'Correct for the 12 tenants whose portion is $0; an under-charge for the other 14 '
                '(worst case 2869 Barrett, real fee $77.70 vs $15.00 stored). Needs a client decision '
                'or a late_fee_percent column before this data drives real billing.',
                'DEPOSIT CONFLICT 2869 Barrett: spreadsheet $600.00, signed lease $300.00. Lease used.',
                '2472 Holland: the 2007 lease is Section 8 (MHA paid $479) but the sheet now marks it '
                'not-subsidised, month-to-month at $725. Loaded as market rate per the sheet; its '
                'end_date is the only date in the set not taken from a document.',
                '2472 Holland has no bedroom or bathroom count in either source.',
                'hap_contract_number is NULL for all 25 subsidised leases — not captured from the HAP contracts.',
                'utility_responsibility is set for 3 leases only; the other 24 utility tables were not read.',
                'Tenant email/phone/emergency contact absent from both sources — no portal invites possible.',
            ],
        ),
        housing_authority=dict(
            name='Housing Authority of Macon-Bibb County',
            contact_name='Maddie Hudgens, S8 Leasing Specialist',
            contact_email=None, contact_phone=None,
            remittance_type='per_tenant',   # [GATE Q-2] unanswered; schema default
        ),
        properties=list(props.values()),
        records=records,
    )

    out = HERE / 'portfolio.json'
    out.write_text(json.dumps(doc, indent=1), encoding='utf-8')

    # ---- validations, mirroring LeaseRequest -------------------------------
    bad = [r for r in records
           if D(r['lease']['tenant_portion']) + D(r['lease']['ha_portion'])
           != D(r['lease']['total_contract_rent'])]
    assert not bad, f'AC-REG-03 portions do not sum: {[b["key"] for b in bad]}'
    bad = [r for r in records if r['lease']['end_date'] <= r['lease']['start_date']]
    assert not bad, f'end_date not after start_date: {[b["key"] for b in bad]}'
    assert all(1 <= r['lease']['rent_due_day'] <= 28 for r in records), 'AC-REG-06 due day out of range'

    print(f'wrote {out}')
    print(f'  {len(props)} properties, {len(records)} units/tenants/leases')
    print(f'  subsidised {sum(1 for r in records if r["lease"]["is_subsidised"])}, '
          f'market {sum(1 for r in records if not r["lease"]["is_subsidised"])}')
    print(f'  monthly contract rent {sum(D(r["lease"]["total_contract_rent"]) for r in records)}')
    print('  AC-REG-03 / end_date / due-day validations: PASS')


if __name__ == '__main__':
    main()
