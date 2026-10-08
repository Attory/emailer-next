# Send a newsletter

These steps describe the 1.0.2 workspace. An Owner can carry out the whole flow.
Operators prepare newsletters; Administrators approve them.

1. Open **Campaigns → Create newsletter**. You can also start with **Send to**
   beside a list or **Send** beside a template.
2. **Recipients:** give the campaign an internal name and select one or more
   lists, or upload a CSV into a new named list. Repeated contacts and global
   unsubscribes are excluded. Uploaded recipients require a separate count
   review and **Add reviewed recipients** before proceeding.
3. **Content:** select and preview a saved template, or upload/paste HTML with
   its template name, email subject and preheader. Saving creates a draft and
   sends nothing.
4. **Sending rate:** enter messages per minute, or leave it blank to use the
   installation ceiling. The lower campaign/installation limit applies; the
   shared destination-domain and worker limits can lower actual throughput.
5. Save the draft and choose **Send a test to myself**. The test goes to the
   signed-in administrator's address. Check its content in your inbox.
6. Choose **Review newsletter**. Check recipients, suppressed count, sender,
   template, tracking and RSVP. Choose **Approve newsletter**, confirming your
   password and MFA when requested. Approval locks the recipient and content
   snapshots. A changed draft needs a fresh test and review.
7. Choose **Send now**, or **Schedule newsletter** using the displayed
   organisation time zone. Times in skipped/repeated daylight-saving hours
   are rejected; choose an unambiguous time.
8. Leave the newsletter page open to watch the progress bar and counts. Use
   **Pause delivery**, adjust the pace while paused, then **Resume delivery**.
   **Cancel remaining deliveries** stops remaining work; a message already
   being transmitted may still finish.

**Installation limits:** Owners use **Settings & tools → Delivery limits** to
change messages per minute, worker concurrency, maximum delivery attempts and
maximum recipients. Confirmation happens when saving. These limits apply to
all campaigns together; changing them never resets a spent rate window.

**Reading progress:** SMTP accepted means the mail transport accepted the
message, not that it reached an inbox. Processed includes accepted messages,
failures, suppressions, cancellations and uncertain outcomes. Uncertain
messages are not automatically resent. A failed live refresh keeps the last
confirmed counts; refresh/sign in again to obtain current status. Without
JavaScript, all preparation forms remain usable and **Refresh page** updates
progress.

Editing a draft retains its selected published template version even if a newer
version exists. Select a new version explicitly when desired. If that template
has been archived, choose an available template before saving. Editing never
changes an approved newsletter's immutable content or audience.
