-- 067: the "Interested" buttons on suggested cards (the Discover decks'
-- DecisionRow and the planner's guide cards) drop their "♥ " prefix — the
-- buttons draw a handshake icon instead (NavIcon.vue), keeping it
-- business-casual rather than romantic.
--
-- Re-runnable.

SET NAMES utf8mb4;

UPDATE `translations`
SET `value` = TRIM(LEADING '♥ ' FROM `value`)
WHERE `key` IN ('common.decision_row.interested', 'planner.card.interested', 'planner.card.picked')
  AND `value` LIKE '♥ %';
