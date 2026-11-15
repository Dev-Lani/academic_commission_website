# Revision requests: manual test plan

You need two accounts (a client and a commissioner) and one order that has been agreed and delivered.
To test the deadline quickly, set `REVISION_HOURS` in `src/config.php` to a tiny value (or edit `due_by` in phpMyAdmin).

1. **Happy path.** Client opens the preview, types what to fix, presses "Request revision".
   - Order shows "Revision requested" for both sides. Escrow is still "Held in escrow". No new row in `transactions`.
   - Commissioner sees the notes, a countdown, and "Deliver the revised work".
2. **Redeliver.** Commissioner uploads new files. Order goes back to "Delivered".
   - Client's preview is closed again until they press "Start preview" (fresh 10 minutes).
   - `order_output_history` has the old version, `revision_requests.fulfilled_at` is filled.
3. **Limit.** Request a second revision (allowed), then check a third: the form is replaced by "You have used all 2 revision requests". Approve and Dispute still work.
4. **Approve after a revision.** Payment is released once. One `release` and one `fee` row in `transactions`.
5. **Dispute after a revision.** Works as before (needs the new preview to be opened first).
6. **Rules.** Request with the preview not opened: refused. Notes under 10 characters: refused. Another client or the commissioner posting `action=revision`: ignored.
7. **Overdue revision.** Let `due_by` pass without a delivery, then load any page. The order becomes "Under dispute" with an automatic reason, the escrow stays held, and the admin sees it under Open disputes.
8. **Auto-release unchanged.** A delivered order with no response past `review_by` still releases by itself.
9. **Notifications.** Commissioner gets "asked for changes", client gets "delivered the revised work", both in the bell and on the Notifications page.
