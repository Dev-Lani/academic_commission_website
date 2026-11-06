USE acw;

-- Indexes for the hottest queries (forum counts and feed, notification badge, order lookups).
-- To measure their effect: run EXPLAIN on the query, DROP INDEX, run it again, compare.
ALTER TABLE posts         ADD INDEX idx_status_subject (status, subject_id, score);
ALTER TABLE notifications ADD INDEX idx_user_read (user_id, is_read);
ALTER TABLE orders        ADD INDEX idx_comm_status (commissioner_id, status);
ALTER TABLE orders        ADD INDEX idx_client_status (client_id, status);