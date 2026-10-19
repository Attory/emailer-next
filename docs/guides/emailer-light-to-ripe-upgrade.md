# Upgrade Emailer Light to Ripe

Ripe 1.2.3 | Beginner guide for Light 1.0.0, 1.1.0, 1.2.0, 1.2.1, 1.2.2 and 1.2.3

Upgrade with the private Ripe 1.2.3 setup file. Your Light administrator becomes an Owner and keeps the same password. Delivery remains held until you accept the validated upgrade, check the result and deliberately resume.

## Supported sources

| Installed source | Target and route |
| --- | --- |
| Original published Light 1.0.0 | Ripe 1.2.3, direct guided upgrade. |
| Official Light 1.1.0 | Ripe 1.2.3, direct guided upgrade. |
| Official Light 1.2.0 | Ripe 1.2.3, direct guided upgrade. |
| Official Light 1.2.1 | Ripe 1.2.3, direct guided upgrade. |
| Official Light 1.2.2 | Ripe 1.2.3, direct guided upgrade. |
| Official Light 1.2.3 | Ripe 1.2.3, direct guided upgrade. |
| Light updated through a supported path to 1.1.0, 1.2.0, 1.2.1, 1.2.2 or 1.2.3 | Ripe 1.2.3, same guided edition upgrade. |
| Ripe 1.0.6, 1.1.0 or 1.2.1 | Ripe 1.2.3, normal Update menu instead. |

Use the new Ripe 1.2.3 installer for all six Light sources. Earlier Ripe 1.0.6 does not recognize Light 1.1.0/1.2.0/1.2.1/1.2.2/1.2.3, and Ripe 1.1.0 does not add Light 1.2.0/1.2.1/1.2.2/1.2.3 support. Original Light 1.0.0 remains supported. There is no general Ripe-to-Light downgrade.

Use Ubuntu 22.04/24.04 amd64/systemd with Apache, or Debian 13 with Nginx, PHP 8.4 and supported MariaDB 10.11/11.8. Ubuntu 22.04 fresh installations use an isolated MariaDB 10.11.19 beside any existing host database. The installer discovers protected standard settings under /etc/emailer-light or /etc/emailer, a dedicated application database account, private/key storage and an existing trusted HTTPS hostname without a custom port/subfolder. A dedicated Emailer virtual-host file must not also serve unrelated routes or applications. Unknown customized targets stop before handoff; keep their existing configuration.

## 1. Prepare for maintenance

Finish intentional sending and allow a short maintenance period. Keep the current HTTPS address and signing keys. Know the existing administrator login and password. If the database needs a separate administrator sign-in, have it ready for the hidden conditional prompt. Setup itself pauses delivery, waits for in-flight work, backs up and verifies recovery material, and fences the old application account. No migration commands or service files need to be written.

## 2. Download and start Ripe setup

While signed in with access, download **emailer-ripe-1.2.3-setup.run** from the [official private release](https://github.com/Attory/emailer-next/releases/tag/v1.2.3), or use an authorized private copy. Upload that one file to the existing server through your provider's file-transfer facility. Do not put a GitHub token in a shell command. In the folder containing the file, run:

```bash
sudo sh emailer-ripe-1.2.3-setup.run
```

Initial trust comes from the official HTTPS or authorized private delivery channel. Embedded established signing identities authenticate the Ripe package and all six supported Light predecessor packages. You do not need to assemble verifier source, sidecars, version arguments or commit hashes.

## 3. Select and upgrade

If asked about an existing Light installation, choose **Upgrade this Light installation to Ripe**. If both standard installations are found, select the intended address when asked. A custom or unrecognized target stops without adoption. Check the displayed Light version and address, then choose **Upgrade with verified recovery material**. Wait for named progress and validation. Retain the printed recovery location.

![Actual Light to Ripe management menu and successful upgrade output.](images/emailer-ripe-upgrade.png)

If interrupted, start the same file and choose **Recover an interrupted upgrade** before retrying. If the message says conversion committed, follow its Accept instruction. Never delete operation journals or edit migration records to retry.

## 4. Accept the validated upgrade, then sign in

1. After the automatic validation succeeds, start the same setup file again and choose **Accept a validated upgrade**. This completes the host handoff and reopens sign-in. The public site stays under maintenance until this action; delivery remains held separately.
2. Open the existing HTTPS login address and use your unchanged administrator login and password. Old sessions are revoked; the account is now an Owner.
3. Check campaigns, templates, assets, recipient snapshots, delivery/tracking history, suppressions and existing links. Campaign-owned Light recipients remain campaign-owned; no contacts or list memberships are manufactured.

The transition preserves the password hash, installation identity, SMTP credentials and encryption/token contexts, relevant backups and required audit history. No campaign is approved or newsletter sent as an upgrade side effect. After acceptance, recovery cannot be replayed as a downgrade.

## 5. Complete security and mail checks

Light upgrades start Ripe at High security with MFA unenrolled. Open Settings & tools > MFA, confirm your credentials, start enrollment, add its setup key to an authenticator app, save the recovery codes privately and confirm a current code. Do not share that secret screen.

![MFA enabled after enrollment, without exposing recovery material.](images/emailer-ripe-mfa.png)

Open Mail settings. Existing SMTP settings should be retained. Choose **Test connection without sending** to validate TLS and sign-in without emailing anyone. If Light never had SMTP configured, you can keep preparing drafts and add it later. A message requires a separate shown destination and explicit Send test email action.

## 6. Resume when ready

Resolve Doctor's sending blockers and review previously approved or scheduled campaigns: lifting the hold can allow existing due work to continue. Start the same setup file, choose **Resume delivery after reviewing readiness** and answer yes. All normal sending checks must pass. The action does not approve any draft.

![Actual acceptance output. Sign in and complete MFA, then review Doctor before choosing Resume delivery.](images/emailer-ripe-resume.png)

If you are not ready, exit; drafting remains available. Keep recovery material after acceptance.

## If something stops

| Situation | Action |
| --- | --- |
| Delivery still has active work | Let the current message finish and retry. Never reset claims manually. |
| Package, version or schema mismatch | Retain source/recovery files and use the original official package. Do not replace signing keys or migration records. |
| Interrupted before commit | Choose Recover, verify the restored source, then retry. |
| Committed conversion | Choose Accept a validated upgrade. A completed operation cannot be downgraded by recovery. |
| Resume blocked | Open Doctor, complete MFA/mail settings and resolve the named finding, then retry Resume. |

Continue with the [Ripe manual](emailer-ripe-manual.md). Use the [operator reference](emailer-ripe-operator.md) for exceptional host and recovery cases.
