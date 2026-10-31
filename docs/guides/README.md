# Ripe documentation - 1.3.2

Use the beginner guide for the normal installation path. The HTML files are self-contained and open in a browser; PDFs contain selectable text and copyable commands. All screenshots show the actual application and installer.

| Document | Read in repository | Offline HTML | PDF |
| --- | --- | --- | --- |
| Upgrade Emailer Light to Ripe | [Read](emailer-light-to-ripe-upgrade.md) | [HTML](https://github.com/Attory/emailer-next/releases/download/v1.3.2/emailer-light-to-ripe-upgrade.html) | [PDF](https://github.com/Attory/emailer-next/releases/download/v1.3.2/emailer-light-to-ripe-upgrade.pdf) |
| Install Emailer Ripe | [Read](emailer-ripe-install.md) | [HTML](https://github.com/Attory/emailer-next/releases/download/v1.3.2/emailer-ripe-install.html) | [PDF](https://github.com/Attory/emailer-next/releases/download/v1.3.2/emailer-ripe-install.pdf) |
| Emailer Ripe user manual | [Read](emailer-ripe-manual.md) | [HTML](https://github.com/Attory/emailer-next/releases/download/v1.3.2/emailer-ripe-manual.html) | [PDF](https://github.com/Attory/emailer-next/releases/download/v1.3.2/emailer-ripe-manual.pdf) |
| Ripe operator and troubleshooting reference | [Read](emailer-ripe-operator.md) | [HTML](https://github.com/Attory/emailer-next/releases/download/v1.3.2/emailer-ripe-operator.html) | [PDF](https://github.com/Attory/emailer-next/releases/download/v1.3.2/emailer-ripe-operator.pdf) |

The release also provides an edition documentation ZIP. The combined documentation ZIP containing both editions is private and supplied separately to Anton. Public Light downloads contain only public Light material.

For maintainers: reviewed JSON and PNG sources live here. `tools/docs/build-guides.py` builds matching HTML/PDF documents; never replace real screenshots with invented screens.


## Automatic maintenance completion

Manually initiated setup temporarily excludes sending while files, database and services change. Successful live verification automatically removes that operation’s temporary exclusion and matching recovered predecessor blocks. No extra Finish or Resume is required after success. An edition transition that needs Accept automatically completes delivery release after successful Accept. Independent owner pauses, campaign approval, suppression/unsubscribe, MFA and SMTP sending rules remain enforced. Existing approved due work can continue; drafts are never approved and failed or uncertain messages are never retried.

A failed or interrupted installation remains blocked with the concrete failure and its recovery action. Finish is an interrupted service-handoff recovery action, not a normal post-success delivery step. Historical recovery receipt omissions do not invalidate a later fully verified successful handoff. Original recovery journals and backups remain unchanged. Standalone native core migration is only part of a supervised operation: its caller must finish service verification and automatic completion within that same operation; core-only output is not whole-operation success.
