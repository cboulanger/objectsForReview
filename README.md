# Objects for Review Plugin for OJS 3.5


## About
This plugins builds on the idea of the OFR and BFR plugins for OJS 2.x to provide support for reviews of books and other material being published on OJS and OMP.

User stories to be covered as basic functionality:

- As an author, submit metadata of the reviewed object together with the review's manuscript.
- A a reader, see metadata of the reviewed object on the article landing page in dedicated areas and have a link to access the digital version of the reviewed object (if available).

## Review workflow

Editors offer works for review; the public page "Books for review" lists them.

- **Book review editor**: a user group (base role Section editor) created by the plugin. Members, and journal managers, use the plugin's backend pages; only managers change its settings. Each offer has a responsible book review editor who receives its notifications.
- **Publisher contact**: a user group (base role Reader) for the people at publishers who handle review copies, with the publisher as affiliation and their mailing address. They never need to log in.
- **Assignment modes**, chosen per offer:
  - *Direct reservation*: the first person to accept the review agreement gets the review.
  - *Bidding*: reviewers apply (with an optional statement, until an optional deadline). The book review editor selects one in *To-do*, which closes bidding and sends a contract offer with a one-click link to the agreement. The other bidders are told they were not selected and stay on the list; if the reviewer declines, retracts or is cancelled, the editor selects again (with a separate "offer available again" email) or reopens bidding.
- **Review copies**: each offer can name a publisher contact, record that the journal holds a copy, require printed copies to be returned if no review materialises, and ask for the publisher to be notified of the published review. When a reviewer wants a printed or electronic copy, it appears under *To-do › Review copies*, where the book review editor records each step, with a prefilled, editable email where one is needed:
  - request the copy from the publisher (with the delivery address or the reviewer's email), or send it from the journal's stock;
  - the publisher has sent it; the reviewer has received it (the reviewer can also confirm receipt on the public page). The delivery address and phone number are deleted at this point;
  - if no review will come: request the return of a printed copy, to the journal (the responsible book review editor's address) or to the publisher contact. A copy returned to the journal is available for the next reviewer. Copies never go from one reviewer to the next.
  - after publication: notify the publisher, if they asked for it.
  The offer form checks that the addresses needed for returns are entered in the users' profiles.
  Publishers and reviewers can also take their steps with one click from the emails: the publisher confirms "copy sent" and "returned copy arrived", the reviewer "received" and "sent back"; each confirmation sends the next person their email and link. Unanswered copy requests are repeated to the publisher every 14 days (configurable).
- **Reviewers** get a confirmation with the agreement details after accepting it, with an "I can't write this review" link (also in the due date reminder). Overdue reviews are listed on the To-do page.
- **One-click links** in emails (accept or decline the contract, leave the list, the review copy steps, withdraw from a review) need no login. Opening a link never changes anything; the page asks for confirmation. Links expire and can be used once.
- A daily task reminds reviewers before their review is due, reminds selected bidders who have not answered the contract offer, expires unanswered offers, and reminds publishers of unanswered copy requests.
- Every step is recorded in the offer's history (audit trail), and all emails are editable templates under Settings › Emails.

See issue #1 for the full concept and its discussion.

The frontend object listing uses https://github.com/tristen/tablesort for table sorting.

## License

This plugin is licensed under the GNU General Public License v3. See the file LICENSE for the complete terms of this license.

The license for https://github.com/tristen/tablesort included in the repository is MIT. See https://github.com/tristen/tablesort/blob/gh-pages/LICENCE
