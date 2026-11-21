---
name: objects-for-review-api
description: Import data into the OJS "Objects for Review" plugin with an API key — works offered for review (looked up by DOI or ISBN), reviewers and publisher contacts, and review processes already under way (bids, contract offers, accepted reservations, submitted or published reviews). Use when asked to add books to a journal's review list, bulk-import review copies or reviewers, migrate book review bookkeeping (spreadsheets, another system) into OJS, or script the Objects for Review plugin.
---

# Objects for Review API client

The plugin serves a REST API at `<journal URL>/api/v1/objectsForReview/`. This folder has:

| Path | Contents |
| --- | --- |
| `bin/ofr.js` | Command line client (`ofr`); JSON on stdout, progress and errors on stderr |
| `src/client.js` | `ObjectsForReviewClient` for Node scripts (no dependencies, Node 18+) |
| `examples/import.example.json` | Template for bulk imports |
| `ObjectsForReviewController.php` | The server side (part of the plugin) |

**Imports never send emails** and create no one-click links. Every imported reviewer or bidder gets an entry ("Imported through the API") in the work's history.

## 1. Setup

On the server (once, by the site admin):

1. `config.inc.php`, section `[security]`: set `api_key_secret` to a long random string. Changing it later invalidates all API keys.
2. The plugin must be enabled in the journal.

For the user who runs the imports:

1. They must be a **journal manager** (or site admin), or a **book review editor**. Only journal managers can give someone the book review editor role.
2. In OJS, under *Profile › API Key*, they tick "Enable external applications…" and click *Generate API key*. The key acts with that user's permissions. Keep it out of files that are committed to git.

On the machine that runs the client:

```sh
cd plugins/generic/objectsForReview/api    # or a copy of this folder anywhere
export OFR_URL=https://example.org/index.php/journalpath
export OFR_TOKEN_FILE=~/.ofr-token          # or OFR_TOKEN=<key>
node bin/ofr.js users --role bookReviewEditor   # connection test
```

`npm link` (in this folder) installs the `ofr` command. Without it, use `node bin/ofr.js`.

**HTTP 401 with a valid key:** many Apache/PHP setups drop the `Authorization` header. Either set `OFR_TOKEN_IN_QUERY=1` (or `--token-in-query`; the key then travels in the URL and ends up in access logs), or fix the server with `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` in the Apache configuration. A 401 that says "The current role does not have access" means the user lacks a role, not that the key is wrong.

## 2. Recommended workflow for an import

1. Get the data into the JSON import format (section 4). For a plain list of books, a text file with one DOI or ISBN per line is enough (`add-books`).
2. Create the people first or include them in `users`: publisher contacts need a **mailing address** if review copies have to be returned (see `returnRequired`).
3. Run `ofr import data.json --dry-run`. It looks up every DOI/ISBN and checks which people and works exist, and changes nothing. Check the titles it found.
4. Run `ofr import data.json`. Exit code 1 means some records failed; the JSON report on stdout lists them under `errors`, and everything else was imported.
5. Fix the failed records and run the same file again. With the default `--on-duplicate skip`, works that exist are left alone, and **their assignments are skipped too**, so nothing is imported twice. Use `--include-existing` to add assignments to works that were already there (for example after fixing an assignment that failed). Assignments that would duplicate an existing bidder or reviewer are refused with 409.

## 3. Commands

```sh
ofr lookup 978-0-262-04630-5            # metadata from Crossref / Open Library; saves nothing
ofr lookup https://doi.org/10.4324/9781003080114
ofr add-book 9780262046305 --publisher-contact rights@press.example --notify-publisher
ofr add-book 10.4324/9781003080114 --mode bidding --bid-deadline 2027-01-31 --no-return
ofr add-books list.txt --mode direct     # one DOI/ISBN per line, "#" comments; existing works are skipped
ofr add-object --data @work.json         # any identifier type (uri, urn, handle, ...)
ofr update-object 12 --data '{"hidden": true}'
ofr list
ofr get 12
ofr find 0-262-04630-X                # matches the ISBN-10 and ISBN-13 of the same book

ofr add-publisher-contact --email copies@press.example --given Petra --family Press \
    --affiliation "Example Press" --address "Example Press\nReview copies\nStreet 1\n12345 Town" --country DE
ofr add-author --email a.reviewer@uni.example --given Alex --family Reviewer --affiliation "Uni"
ofr add-user --email e@journal.example --given Eva --role bookReviewEditor   # journal managers only
ofr users --role publisherContact        # roles: author | publisherContact | bookReviewEditor

ofr add-assignment 12 --data '{"email":"a.reviewer@uni.example","status":"accepted","dueDate":"2027-03-31"}'
ofr assignments 12

ofr import data.json [--dry-run] [--on-duplicate skip|error|update] [--include-existing]
```

`add-book` options that override the lookup: `--title --authors --publisher --year --description --resource-type` (`book`, `bookPart`, `journalArticle`, ... or a COAR URI). `--no-lookup` stores the identifier without querying Crossref or Open Library; `--title` is then required.

## 4. Import format

```json
{
  "users":   [ { "email": "...", "givenName": "...", "roles": ["publisherContact"], ... } ],
  "objects": [ { "isbn": "...", ..., "assignments": [ { "email": "...", "status": "accepted", ... } ] } ]
}
```

See `examples/import.example.json` for a complete example.

### users

| Field | |
| --- | --- |
| `email` | Required. Finds the existing account; otherwise one is created (random password, must be changed at first login, **no email is sent**) |
| `givenName` | Required to create an account |
| `familyName`, `affiliation` | Stored in the journal's primary language |
| `mailingAddress` | Needed for publisher contacts if copies are returned to them, and for an editor who holds copies in stock |
| `phone`, `country` (ISO code, e.g. `DE`), `username` | Optional |
| `roles` | `author` (default; reviewers write the review as authors), `publisherContact`, `bookReviewEditor` |
| `updateProfile` | `true`: overwrite profile fields of an existing account. By default only empty fields are filled |

### objects (works offered for review)

| Field | |
| --- | --- |
| `doi` or `isbn` | Looked up at Crossref (DOI) or Crossref and Open Library (ISBN), then normalized. Fields given in the record override the lookup |
| `identifier` + `identifierType` | Instead of doi/isbn: `doi issn isbn uri urn pmid pmcid purl arxiv ark handle uuid ecli accession other` |
| `lookup` | `false`: don't look up the DOI/ISBN (then `title` is required) |
| `title`, `authors` (`"Family, Given; Family, Given"`), `publisher`, `year`, `description` (HTML allowed) | Metadata |
| `resourceType` | COAR URI; default book. See `RESOURCE_TYPES` in `src/client.js` |
| `assignmentMode` | `direct` (first come, first served) or `bidding`; default from the plugin settings |
| `bidDeadline` | `Y-m-d`, bidding only |
| `responsibleEditorEmail` / `responsibleEditorId` | A book review editor; default: the plugin setting, or the importing user if they are a book review editor |
| `publisherContactEmail` / `publisherContactId` | Must have the publisherContact role (create them first) |
| `journalHoldsCopy` | The journal has a copy in stock |
| `returnRequired` | Default `true`: physical copies go back if no review comes. Then the publisher contact (and, with `journalHoldsCopy`, the responsible editor) must have a mailing address. Set `false` otherwise |
| `notifyPublisher` | Tell the publisher contact when the review is published (needs a publisher contact) |
| `hidden` | Not on the public list |
| `dateCreated` | `Y-m-d` or `Y-m-d H:i:s`, for keeping the original date |
| `onDuplicate` | Per record: `error`, `skip` or `update` (overrides the command line option) |
| `assignments` | Review processes for this work, see below |

### assignments (review processes under way)

`userId`, or `email`. An unknown email creates the account if `givenName` is given (`familyName` and `affiliation` are optional). The person is added to the journal's Author group.

| `status` | Meaning | Rules and fields |
| --- | --- | --- |
| `bid` | Applied, waiting for the editor's choice | Work must be in `bidding` mode. `statement` |
| `unsuccessful` | Applied, not chosen (on the waiting list) | Bidding mode. `statement` |
| `offered` | Chosen bidder, contract offered, not yet accepted | Bidding mode, one per work. `contractDeadline` (`Y-m-d`; default from settings), `dateOffered`. Closes bidding |
| `accepted` | Reviewer agreed and is writing | One reviewer per work. **`dueDate` required** (`Y-m-d`; may be in the past if overdue). Review copy fields below |
| `submitted` | Review submitted to OJS | One reviewer per work. `submissionId` attaches the work to the review submission (needed for the work to show as "submitted") |
| `completed` | Review published | As submitted |

The importer can't create `declined`, `cancelled` or the invitation statuses. Leave finished or abandoned processes out of the import.

All statuses accept `dateCreated` (when the reservation or bid was made) and `dateStatus` (when the current status was reached).

Review copy (`accepted`, and optionally `submitted`/`completed`):

| Field | |
| --- | --- |
| `copyPreference` | `have` (default: no copy needed), `physical`, `electronic`, `other` (+ `copyNote`) |
| `copyStatus` | `notNeeded`, `pending` (not yet requested), `requested` (asked the publisher), `sent`, `received`, `returnRequested`, `returnSent`, `returned`. Default: `pending` for physical/electronic, else `notNeeded` |
| `shippingAddress`, `phone` | Physical copies that haven't been sent yet (`pending`, `requested`) need `shippingAddress` |
| `copySentBy` | `journal` or `publisher` |
| `returnTarget` | `journal` or `publisher` (for return statuses) |
| `copyStatusDate` | Date of the current copy status |

After the import, the plugin's daily task treats imported processes like any others, so check the dates before importing:

- reviewers get a reminder when `dueDate` is close;
- publisher contacts get reminders about `requested` copies, counted from `copyStatusDate` (default: the time of the import);
- `offered` contracts expire after `contractDeadline`, and the bidder is reminded before that.

## 5. Use from a Node script

```js
import { ObjectsForReviewClient, OfrApiError } from './src/client.js';

const ofr = new ObjectsForReviewClient({ url: process.env.OFR_URL, token: process.env.OFR_TOKEN });

const contact = await ofr.addPublisherContact({ email: 'copies@press.example', givenName: 'Petra', mailingAddress: '...' });
const work = await ofr.addBook('978-0-262-04630-5', { publisherContactId: contact.id, onDuplicate: 'skip' });
try {
  await ofr.addAssignment(work.id, { email: 'a.reviewer@uni.example', status: 'accepted', dueDate: '2027-03-31' });
} catch (error) {
  if (error instanceof OfrApiError && error.status === 409) { /* already there */ } else throw error;
}

const report = await ofr.importData(doc, { dryRun: true, onProgress: console.error });
```

Methods: `lookup({doi|isbn})`, `listObjects()`, `findObject({doi|isbn|identifier, identifierType})`, `getObject(id)`, `addObject(data)`, `addBook(doiOrIsbn, extra)`, `updateObject(id, data)`, `listAssignments(id)`, `addAssignment(id, data)`, `listUsers({role, email})`, `findUserByEmail(email)`, `addUser(data)`, `addAuthor(data)`, `addPublisherContact(data)`, `importData(doc, options)`.

## 6. HTTP API reference

Base: `<journal URL>/api/v1/objectsForReview`, header `Authorization: Bearer <key>`, JSON bodies.

| Method and path | |
| --- | --- |
| `GET lookup?doi=…` / `?isbn=…` | Metadata; nothing saved |
| `GET objects[?identifier=…&identifierType=…]` | `{itemsMax, items}` |
| `POST objects` | Create (fields above); result has `_existing: true` if skipped/updated |
| `GET objects/{id}` | Work with `assignments` |
| `PUT objects/{id}` | Change the fields given |
| `GET objects/{id}/assignments` · `POST objects/{id}/assignments` | List · import |
| `GET users[?role=…][&email=…]` · `POST users` | List · find or create; result has `_created`, `_rolesAdded` |

Errors are `{"error": "<code>", "errorMessage": "<text>"}` with status 400 (invalid input), 403 (not allowed), 404, 409 (duplicate: `duplicateIdentifier` with `objectId`, `duplicateAssignment` with `assignmentId`, `reviewerExists`, `offerExists`, `submissionExists`) or 502 (lookup service failed; retry later).

## Local test journal

`dev/ojs-dev.sh up` starts OJS with the plugin at `http://localhost:8080/index.php/test`. Images built after this API was added have `api_key_secret` set and pass the Authorization header through; for an older image run `dev/ojs-dev.sh build` and `dev/ojs-dev.sh reset`. Log in as `admin` / `ojsadmin`, generate a key under *Profile › API Key*, and set `OFR_URL=http://localhost:8080/index.php/test`. Offline tests of the client: `npm test` (in this folder). The API's end-to-end tests: `tests/run.sh e2e/api` in the plugin (see `tests/README.md`).
