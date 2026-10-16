# Emailer Ripe - Basic newsletter manual

The complete [1.1.0 user manual](guides/emailer-ripe-manual.md) covers installation defaults, deferred SMTP, secure provider checks, MFA, updates and delivery holds. Download HTML/PDF from the [documentation index](guides/README.md).

Fresh installations can prepare templates and drafts without SMTP. Add the provider under Settings & tools > Mail settings when ready; connection testing sends no message. An actual test email requires an explicit shown destination.


**Prepare → Test → Approve → Send.** Saving a draft sends nothing.

A **campaign** is one newsletter sent to an audience. A **template** is reusable
email content. A **list** is a group of contacts.

Owners can do the whole process. Operators prepare, test and send approved
campaigns; Administrators and Owners approve. Auditors can view campaigns.

The printable guide is available inside the application through
**Settings & tools → Newsletter guide**, and from **Campaigns**. Use your
browser’s **Print → Save as PDF** to keep a copy.

## Create a campaign

1. Open **Campaigns → Create newsletter**. Alternatively, use **Send to** beside
   a list or **Send** beside a template.
2. **Recipients:** enter an internal campaign name, then select existing lists
   or upload a CSV into a new named list. Map its email column. This internal
   name is not the email subject. Only email people you have permission to contact.
3. **Content:** select and preview a saved template, or upload/paste new HTML.
   For new content, enter the template name, email subject and preheader. The
   preheader is the short preview shown beside the subject in many inboxes.
4. **Sending rate:** enter messages per minute, or leave blank to inherit the
   installation limit. This is a maximum. For example, 50 contacts at 10/minute
   take several minutes; domain limits and retries can make it longer.
5. **Review:** check the selections and save the draft. For a CSV upload, review
   its counts on the new campaign page and choose **Add reviewed recipients**
   before testing or reviewing the newsletter.

Duplicate contacts and global unsubscribes are excluded from delivery.
Reimporting or duplicating a list never removes unsubscribe protection.

## Edit a campaign

1. Open **Campaigns** and click the campaign’s name.
2. Choose **Edit draft**. It is available for **Draft** and **Review** campaigns,
   once any pending CSV import has been committed.
3. Change the internal name, lists, selected template version or rate, and save.
   Editing a campaign in Review returns it to Draft.
4. To change the wording, subject or preheader, use **Templates → Edit** and save
   a new version. Return to **Edit draft** and explicitly select the new version.
   A draft keeps its earlier template version until you change it.
5. Send a fresh test and review again. Previous tests do not confirm edited content.

Use **Lists → View/Edit** to add, remove or correct members before approval.
Approval locks the content and audience for that campaign. After approval,
create a new campaign for changed content or recipients; cancel the old
campaign’s remaining delivery if necessary. Cancellation cannot recall a
message already sent or stop one already being transmitted.

## Test, approve and send

1. Choose **Send a test to myself**. It goes to your signed-in account’s email
   address. Wait for acceptance, then check its subject, layout, links and
   unsubscribe footer in your inbox.
2. Choose **Review newsletter** and check the eligible/suppressed counts,
   template, sender, time zone, tracking and RSVP. The audience is recorded
   at approval, so its final count can differ from an earlier preview.
3. An Administrator or Owner chooses **Approve newsletter** and confirms their
   own password and MFA when requested. Operators need an Administrator or
   Owner to approve.
4. Choose **Send now**, or set the date/time and choose **Schedule newsletter**.
   Use the displayed organisation time zone and avoid skipped/repeated
   daylight-saving hours.

**Approval does not start delivery.** Send now or scheduling is a separate step.

## Progress and sending rate

The campaign page shows the live progress bar and delivery counts.
**SMTP accepted** means the mail server accepted the message; it does not prove
inbox placement. Processed also includes failures, unsubscribes, cancellations
and uncertain outcomes.

- **Pause delivery**, adjust the rate while paused, then **Resume delivery**.
- **Cancel remaining deliveries** stops the remaining audience.
- **Waiting/Retrying** means recipients are pending or being retried; rate limits
  may keep them waiting.
- **Uncertain** outcomes are not automatically resent.

An Owner changes installation limits under **Settings & tools → Delivery limits**.
Installation, destination-domain and concurrency limits still apply to campaigns.

## If something is unavailable

| Symptom | What to do |
| --- | --- |
| Edit draft is missing | Check your role and campaign status; commit any pending import. Approved content cannot be edited. |
| A template change did not update the campaign | Select the new version in Edit draft and send a fresh test. |
| Approval is unavailable or the test is outdated | Wait for an accepted test of this draft; after editing, send another. Only Administrators and Owners approve. |
| Delivery is blocked or progress does not move | Check Doctor, paused/scheduled status and the worker/scheduler; ask the installation operator if services need attention. |
| Counts stop refreshing | Refresh the page or sign in again. Without JavaScript, use Refresh page. |

## Creating an Operator account

An Owner or Administrator opens **Settings & tools → Users**, enters the new
account’s email and initial password, selects **Operator**, and clicks
**Create account**. When prompted, enter **your own current password** and your
authenticator/recovery code if enrolled. The new account’s initial password is
separate from your identity confirmation. Confirmation lasts five minutes for
user management in this browser. If it fails, passwords must be entered again.
