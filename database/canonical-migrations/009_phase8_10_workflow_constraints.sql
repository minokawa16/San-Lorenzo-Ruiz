-- Phase 8-10 follow-up constraints discovered during workflow integration tests.
ALTER TABLE baptism_records ADD UNIQUE KEY uq_baptism_request (request_id);
ALTER TABLE confirmation_records ADD UNIQUE KEY uq_confirmation_request (request_id);
ALTER TABLE first_communion_records ADD UNIQUE KEY uq_communion_request (request_id);
ALTER TABLE marriage_records ADD UNIQUE KEY uq_marriage_request (request_id);
ALTER TABLE funeral_records ADD UNIQUE KEY uq_funeral_request (request_id);

ALTER TABLE certificate_issuances
  MODIFY issued_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN drafted_by INT NULL AFTER issued_to,
  ADD COLUMN drafted_at DATETIME NULL AFTER drafted_by,
  ADD COLUMN reissue_requested_by INT NULL AFTER reissue_reason,
  ADD CONSTRAINT fk_certificate_drafter FOREIGN KEY (drafted_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_certificate_reissue_requester FOREIGN KEY (reissue_requested_by) REFERENCES users(id) ON DELETE SET NULL;
UPDATE certificate_issuances SET drafted_by=issued_by,drafted_at=issued_at WHERE drafted_at IS NULL;
UPDATE certificate_issuances SET issued_at=NULL WHERE status IN ('draft','review','approval');
