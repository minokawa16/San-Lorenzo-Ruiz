-- Migration 024: Populate authentic Baptism Certificate data for Rey Mark Cantomayor Cavañas

UPDATE baptism_records SET 
    fullname = 'REY MARK CANTOMAYOR CAVAÑAS',
    birth_date = '2005-12-16',
    birth_place = 'San Mateo, Aleosan, Cotabato',
    baptism_date = '2006-04-23',
    parents = 'Roberto Amualla Cavañas and Joy C. Cantomayor',
    parent_address = 'San Mateo, Aleosan, Cotabato',
    godparents = 'Nida Paredes, Reynante Pan',
    priest = 'Rev. Fr. Heriberto C. Villas, O.M.I.',
    parish_priest = 'REV. FR. HERIBERTO C. VILLAS, O.M.I.',
    parish_secretary = '',
    remarks = 'Father\'s Birthplace: San Mateo, Aleosan, Cotabato | Mother\'s Birthplace: San Mateo, Aleosan, Cotabato | Signatory: REV. FR. HERIBERTO C. VILLAS, O.M.I. (Priest-in-Charge)',
    book_no = '1',
    page_no = '2'
WHERE baptism_id = 1;

UPDATE baptism_records SET 
    fullname = 'REY MARK CANTOMAYOR CAVAÑAS',
    birth_date = '2005-12-16',
    birth_place = 'San Mateo, Aleosan, Cotabato',
    baptism_date = '2006-04-23',
    parents = 'Roberto Amualla Cavañas and Joy C. Cantomayor',
    parent_address = 'San Mateo, Aleosan, Cotabato',
    godparents = 'Nida Paredes, Reynante Pan',
    priest = 'Rev. Fr. Heriberto C. Villas, O.M.I.',
    parish_priest = 'REV. FR. HERIBERTO C. VILLAS, O.M.I.',
    parish_secretary = '',
    remarks = 'Father\'s Birthplace: San Mateo, Aleosan, Cotabato | Mother\'s Birthplace: San Mateo, Aleosan, Cotabato | Signatory: REV. FR. HERIBERTO C. VILLAS, O.M.I. (Priest-in-Charge)',
    book_no = '1',
    page_no = '2'
WHERE baptism_id = 2;

UPDATE certificate_issuances 
SET issued_to = 'REY MARK CANTOMAYOR CAVAÑAS' 
WHERE record_table = 'baptism_records' AND record_id IN (1, 2);
