# Objects for Review Plugin for OJS 3.5


## About
This plugins builds on the idea of the OFR and BFR plugins for OJS 2.x to provide support for reviews of books and other material being published on OJS and OMP.

User stories to be covered as basic functionality:

- As an author, submit metadata of the reviewed object together with the review's manuscript.
- A a reader, see metadata of the reviewed object on the article landing page in dedicated areas and have a link to access the digital version of the reviewed object (if available).

## Review workflow

Editors offer works for review; the public page "Books for review" lists them.

- **Book review editor**: a user group (base role Section editor) created by the plugin. Members, and journal managers, use the plugin's backend pages; only managers change its settings. Each offer has a responsible book review editor who receives its notifications.
- **Publisher contact**: a user group (base role Reader) for the people at publishers who handle review copies. They never log in; the review copy workflow (planned) reaches them through email links.
- **Assignment modes**, chosen per offer:
  - *Direct reservation*: the first person to accept the review agreement gets the review.
  - *Bidding*: reviewers apply (with an optional statement, until an optional deadline). The book review editor selects one in *To-do*, which closes bidding and sends a contract offer with a one-click link to the agreement. The other bidders are told they were not selected and stay on the list; if the reviewer declines, retracts or is cancelled, the editor selects again (with a separate "offer available again" email) or reopens bidding.
- **One-click links** in emails (accept or decline the contract, leave the list) need no login. Opening a link never changes anything; the page asks for confirmation. Links expire and can be used once.
- A daily task reminds reviewers before their review is due, reminds selected bidders who have not answered the contract offer, and expires unanswered offers.
- Every step is recorded in the offer's history (audit trail), and all emails are editable templates under Settings › Emails.

See issue #1 for the full concept, including the planned review copy workflow.

The frontend object listing uses https://github.com/tristen/tablesort for table sorting.

## License

This plugin is licensed under the GNU General Public License v3. See the file LICENSE for the complete terms of this license.

The license for https://github.com/tristen/tablesort included in the repository is MIT. See https://github.com/tristen/tablesort/blob/gh-pages/LICENCE
