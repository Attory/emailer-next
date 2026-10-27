<?php

declare(strict_types=1);

namespace App\Module\Campaigns\Presentation\Http;

final class CampaignDraftMessages
{
    public static function save(\Throwable $failure): string
    {
        if ($failure instanceof \Symfony\Component\HttpFoundation\Exception\BadRequestException) {
            return 'Campaign fields must be ordinary text values. Your other entries are retained; check the form and retry Save draft.';
        }
        if ($failure instanceof \InvalidArgumentException && \in_array($failure->getMessage(), ['Published template version is unavailable.', 'Template version is invalid.'], true)) {
            return 'Select an available template and explicitly Publish its version in Templates before testing or reviewing this campaign. Your campaign draft is still saved; no message was sent.';
        }
        $safe = [
            'CSV column mapping must use different columns within the uploaded file.',
            'CSV column numbers must be between 1 and 32.',
            'CSV headings must be unique. Correct duplicate headings or use column mapping.',
            'Choose a CSV format: a heading row or no heading row.',
            'Choose the Email column number under CSV column mapping, or add an email heading to the first row.',
            'Recipient CSV contains no valid recipient rows.',
            'Recipient CSV exceeds the campaign size limit.',
            'Recipient CSV exceeds the configured row limit.',
            'Recipient CSV exceeds the configured size limit.',
            'Recipient CSV is unavailable.',
            'Recipient CSV requires a readable row with at most 32 columns.',
            'Recipient profile value is too long.',

            'Add a campaign name and select a published template before review.',
            'Add at least one eligible campaign recipient before review.',
            'An accepted campaign test is required before approval.',
            'Campaign RSVP can only be configured while draft.',
            'Campaign RSVP selection and template must agree.',
            'Campaign audience size is outside the configured limit.',
            'Campaign cannot be cancelled.',
            'Campaign cannot be queued.',
            'Campaign cannot be scheduled.',
            'Campaign identity is invalid.',
            'Campaign is not awaiting approval.',
            'Campaign is unavailable.',
            'Campaign name is invalid.',
            'Campaign schedule is invalid.',
            'Campaign state transition is not available.',
            'Campaign test is not available in this state.',
            'Campaign upload does not match this draft.',
            'Campaign-owned recipients cannot be replaced with reusable lists.',
            'Change the sending rate while the campaign is a draft or paused.',
            'Choose a time outside the repeated daylight-saving hour.',
            'Choose a valid local date and time.',
            'Choose campaign CSV recipients or reusable lists, not both.',
            'Complete the existing recipient import before editing this campaign.',
            'Enter a campaign name of 1–160 characters.',
            'Enter a campaign name of up to 160 characters without control characters.',
            'Keep this campaign’s existing recipient type. Its audience provenance cannot be changed.',
            'Only unapproved campaign drafts can be changed. The saved campaign is unchanged.',
            'Only unapproved drafts can be edited.',
            'RSVP is disabled by the installation privacy policy.',
            'Recipient CSV contains no valid recipient rows.',
            'Recipient CSV exceeds the campaign size limit.',
            'Recipient CSV exceeds the configured row limit.',
            'Recipient CSV exceeds the configured size limit.',
            'Recipient CSV has an unsupported column.',
            'Recipient CSV header is invalid.',
            'Recipient CSV is unavailable.',
            'Recipient CSV requires a header row.',
            'Recipient CSV upload is invalid.',
            'Recipient profile value is too long.',
            'Recipients are immutable after campaign approval.',
            'Review and commit the uploaded recipients first.',
            'Select a published template before sending a test.',
            'Sending rate must be a whole number.',
            'That template is no longer selectable. Refresh the template choices and select an available version.',
            'Use 1–600 messages per minute, or leave blank to use the installation limit.',
        ];
        if (($failure instanceof \InvalidArgumentException || $failure instanceof \DomainException) && \in_array($failure->getMessage(), $safe, true)) {
            return $failure->getMessage().' Your campaign fields and selected CSV are unchanged; correct the problem and try Save draft again.';
        }

        return 'The draft save could not be confirmed. Your fields and selected CSV are still here. Check Campaigns in another tab before retrying, in case the request was saved. Nothing was sent.';
    }
}
