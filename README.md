# Com-chain API

This API allows Biletujo (Com-Chain multi-currency wallet) and tha admin pages
to communicate with the Com-Chain node back-end. 

It was created and is maintained by Florian and Dominique (see com-chain.org)

## Features 
The files in this api have the following purpose:

Check the node is up:

├── ping.php

├── dbcheck.php

Authentication and authorization:

├── auth.php

├── checkAdmin.php

Access the Blockchain Node:

├── api.php

Access the DB node:

├── enroll.php       - used by the administration

├── getadd.php       - used by the administration

├── getuid.php       - used by the administration

├── trnslist.php     - to retrieve an index-based set of transactions

├── export.php       - to retrieve a time-based set of transaction 

├── keys.php         - store and retrieve message keys 

Access to the IPFS node:

├── ipfsadd.php

├── ipfscat.php


Scripts:

├── parser.py         - for filling the DB from Blckchain Logs

├── update.sh         - for filling the DB from Blckchain Logs

├── webhook.py        - for sending whebhook (called from parser.py and ReSendFailedWebhook.py

├── ReSendFailedWebhook.py


Others files are helpers or library

## Our Philosophy

- Empower the people: Give people the ability to interact with the Ethereum blockchain easily, without having to run a full node.

- Make it easy & free: Everyone should be able to create a wallet and send Tokens without additional cost.
People are the Priority: People are the most important.

- If it can be hacked, it will be hacked: Never save, store, or transmit secret info, like passwords or keys. Open source & auditable.

## Test

Run tests in Docker (no local PHP needed):
- `./bin/test` — defaults to PHP 8.3
- `./bin/test -V 7.4` — choose a PHP version (matrix: >= 7, ie:
  `7.0.33`, `7.4`, `8`)
- `./bin/test -V 7.4,8.3` — run multiple versions in one call (comma-separated)
- `./bin/test -V all` — run every known `php:<N.M>-cli-alpine` tag (discovered
  from Docker Hub and cached in `.docker-cache/php-cli-tag-cache.txt`; cache
  refreshes weekly)

Coverage (HTML in `./.coverage-html`):
- `./bin/test --coverage`
- `./bin/test -V 7.0 --coverage` (works across the matrix)
- After a coverage run, open `.coverage-html/index.html` (a clickable
  link is printed)

Notes
- The runner builds a versioned image and caches by tag
  (`comchain-phpunit:<version>`).
- No repo phpunit.xml required; the script generates one per run.
- Officially exercised versions: 7.0.33, 7.4.33, 8.3.29.

## Contact

If you can think of any other features or run into bugs, let us know. You can drop a line at it {at} monnaie {-} leman dot org.
