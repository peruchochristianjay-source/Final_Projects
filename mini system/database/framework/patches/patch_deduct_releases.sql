USE mini_system_db;

-- Deduct all existing fund releases from each entity's previous_funds
UPDATE entity_funds ef
JOIN (
    SELECT entity_id, SUM(amount) AS total_released
    FROM fund_releases
    WHERE status = 'Released'
    GROUP BY entity_id
) fr ON fr.entity_id = ef.entity_id
SET ef.previous_funds = ef.previous_funds - fr.total_released;
