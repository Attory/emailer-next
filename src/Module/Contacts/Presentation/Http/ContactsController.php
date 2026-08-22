<?php

declare(strict_types=1);

namespace App\Module\Contacts\Presentation\Http;

use App\Module\Contacts\Application\ContactExporter;
use App\Module\Contacts\Application\ContactManager;
use App\Module\Contacts\Application\ImportManager;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\ReauthenticationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class ContactsController extends AbstractController
{
    public function __construct(
        private readonly ContactManager $contacts,
        private readonly ImportManager $imports,
        private readonly ContactExporter $exporter,
        private readonly ReauthenticationService $reauthentication,
    ) {
    }

    #[Route('/contacts', name: 'admin_contacts', methods: ['GET', 'HEAD'])]
    public function contacts(): Response
    {
        return $this->render('admin/contacts.html.twig', ['contacts' => $this->contacts->list($this->user()), 'fields' => $this->contacts->fields($this->user())]);
    }

    #[Route('/contacts/create', name: 'admin_contact_create', methods: ['POST'])]
    public function createContact(Request $request): Response
    {
        if (!$this->csrf($request, 'contact-create')) {
            return $this->badRequest();
        }
        try {
            $this->contacts->create($this->user(), $request->request->getString('email'), $this->profile($request), $this->attributes($request));
            $this->addFlash('success', 'Contact created.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Contact creation was not accepted.');
        }

        return $this->redirectToRoute('admin_contacts', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/contacts/{id}/update', name: 'admin_contact_update', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function updateContact(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'contact-update-'.$id)) {
            return $this->badRequest();
        }
        try {
            $this->contacts->update($this->user(), $id, $request->request->getString('email'), $this->profile($request), $this->attributes($request));
            $this->addFlash('success', 'Contact updated.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Contact update was not accepted.');
        }

        return $this->redirectToRoute('admin_contacts', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/contacts/{id}/archive', name: 'admin_contact_archive', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function archiveContact(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'contact-archive-'.$id)) {
            return $this->badRequest();
        }
        try {
            $this->contacts->archive($this->user(), $id);
            $this->addFlash('success', 'Contact archived.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Contact archive was not accepted.');
        }

        return $this->redirectToRoute('admin_contacts', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/contacts/{id}/reveal', name: 'admin_contact_reveal', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function revealContact(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'contact-reveal-'.$id)) {
            return $this->badRequest();
        }
        $this->reauthentication->assertRecent($request->getSession(), 'contact_data_access');

        return $this->render('admin/contact-reveal.html.twig', ['email' => $this->contacts->reveal($this->user(), $id)]);
    }

    #[Route('/contacts/fields/create', name: 'admin_contact_field_create', methods: ['POST'])]
    public function createField(Request $request): Response
    {
        if (!$this->csrf($request, 'contact-field-create')) {
            return $this->badRequest();
        }
        try {
            $length = $request->request->getString('max_length');
            $this->contacts->defineField($this->user(), $request->request->getString('field_key'), $request->request->getString('label'), $request->request->getString('field_type'), ctype_digit($length) ? (int) $length : null);
            $this->addFlash('success', 'Custom field created.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Custom field creation was not accepted.');
        }

        return $this->redirectToRoute('admin_contacts', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/lists', name: 'admin_lists', methods: ['GET', 'HEAD'])]
    public function lists(): Response
    {
        return $this->render('admin/lists.html.twig', ['lists' => $this->contacts->lists($this->user()), 'contacts' => $this->contacts->list($this->user())]);
    }

    #[Route('/lists/create', name: 'admin_list_create', methods: ['POST'])]
    public function createList(Request $request): Response
    {
        if (!$this->csrf($request, 'list-create')) {
            return $this->badRequest();
        }
        try {
            $this->contacts->createList($this->user(), $request->request->getString('name'));
            $this->addFlash('success', 'List created.');
        } catch (\Throwable) {
            $this->addFlash('error', 'List creation was not accepted.');
        }

        return $this->redirectToRoute('admin_lists', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/lists/{id}/archive', name: 'admin_list_archive', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function archiveList(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'list-archive-'.$id)) {
            return $this->badRequest();
        }
        try {
            $this->contacts->archiveList($this->user(), $id);
        } catch (\Throwable) {
            $this->addFlash('error', 'List archive was not accepted.');
        }

        return $this->redirectToRoute('admin_lists', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/lists/{id}/members/add', name: 'admin_membership_add', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function addMembership(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'membership-add-'.$id)) {
            return $this->badRequest();
        }
        try {
            $this->contacts->addMembership($this->user(), $id, $request->request->getString('contact_id'));
        } catch (\Throwable) {
            $this->addFlash('error', 'Membership change was not accepted.');
        }

        return $this->redirectToRoute('admin_lists', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/lists/{id}/members/remove', name: 'admin_membership_remove', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function removeMembership(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'membership-remove-'.$id)) {
            return $this->badRequest();
        }
        try {
            $this->contacts->removeMembership($this->user(), $id, $request->request->getString('contact_id'));
        } catch (\Throwable) {
            $this->addFlash('error', 'Membership change was not accepted.');
        }

        return $this->redirectToRoute('admin_lists', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/imports', name: 'admin_imports', methods: ['GET', 'HEAD'])]
    public function imports(): Response
    {
        return $this->render('admin/imports.html.twig', ['lists' => $this->contacts->lists($this->user())]);
    }

    #[Route('/imports/upload', name: 'admin_import_upload', methods: ['POST'])]
    public function uploadImport(Request $request): Response
    {
        if (!$this->csrf($request, 'import-upload')) {
            return $this->badRequest();
        }
        $file = $request->files->get('csv_file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Import upload was not accepted.');

            return $this->redirectToRoute('admin_imports', status: Response::HTTP_SEE_OTHER);
        }
        try {
            $id = $this->imports->upload($this->user(), $request->request->getString('list_id'), $request->request->getString('mode'), $file->getPathname());

            return $this->redirectToRoute('admin_import_details', ['id' => $id], Response::HTTP_SEE_OTHER);
        } catch (\Throwable) {
            $this->addFlash('error', 'Import upload was not accepted.');

            return $this->redirectToRoute('admin_imports', status: Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/imports/{id}', name: 'admin_import_details', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function importDetails(string $id): Response
    {
        return $this->render('admin/import-details.html.twig', ['import' => $this->imports->details($this->user(), $id)]);
    }

    #[Route('/imports/{id}/preview', name: 'admin_import_preview', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function previewImport(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'import-preview-'.$id)) {
            return $this->badRequest();
        }
        try {
            $this->imports->preview($this->user(), $id, $this->mapping($request));
            $this->addFlash('success', 'Import preview completed. Review counts before commit.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Import preview was not accepted.');
        }

        return $this->redirectToRoute('admin_import_details', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/imports/{id}/commit', name: 'admin_import_commit', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function commitImport(Request $request, string $id): Response
    {
        if (!$this->csrf($request, 'import-commit-'.$id)) {
            return $this->badRequest();
        }
        try {
            $this->imports->commit($this->user(), $id);
            $this->addFlash('success', 'Import committed.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Import commit was not accepted.');
        }

        return $this->redirectToRoute('admin_import_details', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/imports/{id}/report', name: 'admin_import_report', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function importReport(string $id): Response
    {
        $stream = $this->imports->report($this->user(), $id);

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="import-rejections-'.$id.'.txt"', 'Cache-Control' => 'no-store']);
    }

    #[Route('/exports/contacts', name: 'admin_contact_export', methods: ['POST'])]
    public function exportContacts(Request $request): Response
    {
        if (!$this->csrf($request, 'contact-export')) {
            return $this->badRequest();
        }
        $this->reauthentication->assertRecent($request->getSession(), 'contact_data_access');
        $stream = tmpfile();
        if (false === $stream) {
            throw new \RuntimeException('Export staging is unavailable.');
        }
        try {
            $this->exporter->export($this->user(), $stream);
            if (0 !== fseek($stream, 0)) {
                throw new \RuntimeException('Export staging could not be read.');
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }

        return new StreamedResponse(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="contacts.csv"', 'Cache-Control' => 'no-store']);
    }

    private function user(): AdministrativeActor
    {
        $user = $this->getUser();
        if (!$user instanceof AdministrativeActor) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function csrf(Request $request, string $id): bool
    {
        return $this->isCsrfTokenValid($id, $request->request->getString('_token'));
    }

    private function badRequest(): Response
    {
        return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
    }

    /** @return array<string, string> */
    private function profile(Request $request): array
    {
        return [
            'display_name' => $request->request->getString('display_name'),
            'first_name' => $request->request->getString('first_name'),
            'last_name' => $request->request->getString('last_name'),
            'locale' => $request->request->getString('locale'),
            'time_zone' => $request->request->getString('time_zone'),
            'consent_source' => $request->request->getString('consent_source', 'manual'),
        ];
    }

    /** @return array<string, int> */
    private function mapping(Request $request): array
    {
        $mapping = [];
        foreach (['email', 'display_name', 'first_name', 'last_name', 'locale', 'time_zone', 'consent_source'] as $field) {
            $column = $request->request->getString('column_'.$field);
            if (ctype_digit($column)) {
                $mapping[$field] = (int) $column;
            }
        }

        return $mapping;
    }

    /** @return array<string, string> */
    private function attributes(Request $request): array
    {
        $attributes = [];
        foreach ($this->contacts->fields($this->user()) as $field) {
            if (\is_string($field['field_key'] ?? null)) {
                $attributes[$field['field_key']] = $request->request->getString('attribute_'.$field['field_key']);
            }
        }

        return $attributes;
    }
}
