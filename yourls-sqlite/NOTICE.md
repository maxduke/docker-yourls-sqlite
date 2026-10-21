# SQLite driver provenance and redistribution

Vendored from https://github.com/Flameborn/yourls-sqlite at
`67835bed79236700f035b0cd93120c70f9118ed6` (previous submodule pin).
Original author: Ozh. Fork lineage: ozh → reanimus → Flameborn;
contributors in that history also include Niduroki. Original authorship and
source comments remain in db.php. Local compatibility changes are maintained
in maxduke/docker-yourls-sqlite; they do not imply upstream endorsement.

The pinned upstream README explicitly states under “License”:

> Do whatever the hell you want with it

The original author's README contains the same permission:
https://github.com/ozh/yourls-sqlite/blob/master/readme.md

This broad permission permits copying, modification and redistribution. It is
an informal permission statement, not a named SPDX license or a public-domain
dedication. We preserve it verbatim in UPSTREAM-README.md and do not relabel it
as MIT. The upstream README's old compatibility/encoding claims are historical,
not documentation for this maintained driver (SQLite supports four-byte UTF-8).

The PDO escape method was copied by upstream from YOURLS YDB.php at
`23d21fd996e771c4015f5d8b4ec1b06af122d60b`. YOURLS-derived code is MIT licensed:
Copyright (c) 2009-present, The YOURLS Contributors.
The full permission, copyright and warranty notice is in LICENSE-YOURLS.txt.
The same notice covers local adaptations of YOURLS database helpers and schema.
