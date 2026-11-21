// People: find or create users and give them roles
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { admin, newUser, rejects } from './fixtures.mjs';
import { email, mailsSince, now, sql, uid } from '../../lib/ojs.mjs';

test('create an author: no email, random password, must change it', async () => {
  const since = now();
  const address = email('author');
  const user = await admin().addAuthor({ email: address, givenName: 'Alex', familyName: 'Author', affiliation: 'Uni', country: 'FI', phone: '+358 1234' });
  assert.equal(user._created, true);
  assert.deepEqual(user._rolesAdded, ['author']);
  assert.deepEqual(user.roles, ['author']);
  assert.equal(user.email, address);
  assert.equal(user.fullName, 'Alex Author');
  assert.equal(user.affiliation, 'Uni');
  assert.equal(user.country, 'FI');
  assert.equal(user.phone, '+358 1234');
  assert.match(user.username, /^e2eauthor/);

  const [row] = sql(`SELECT must_change_password, password FROM users WHERE user_id = ${user.id}`);
  assert.equal(row.must_change_password, '1');
  assert.ok(row.password.length > 20, 'a password hash is set');
  assert.deepEqual(mailsSince(since).filter((mail) => mail.to.includes(address)), [], 'no email');
});

test('roles default to author', async () => {
  const user = await admin().addUser({ email: email(), givenName: 'D' });
  assert.deepEqual(user.roles, ['author']);
});

test('validation', async () => {
  await rejects(admin().addUser({ email: 'not-an-email', givenName: 'X' }), 400, 'invalidEmail');
  await rejects(admin().addUser({ givenName: 'X' }), 400, 'invalidEmail');
  await rejects(admin().addUser({ email: email() }), 400, 'givenNameRequired');
  await rejects(admin().addUser({ email: email(), givenName: 'X', roles: ['reviewer'] }), 400, 'invalidRole');
});

test('an existing user: missing profile fields are filled, others kept', async () => {
  const user = await newUser({ givenName: 'Kim', familyName: 'Keep' });
  const again = await admin().addUser({ email: user.email, givenName: 'Other', familyName: 'Name', affiliation: 'New Uni', mailingAddress: 'Street 3' });
  assert.equal(again.id, user.id);
  assert.equal(again._created, false);
  assert.deepEqual(again._rolesAdded, []);
  assert.equal(again.fullName, 'Kim Keep');
  assert.equal(again.affiliation, 'New Uni');
  assert.equal(again.mailingAddress, 'Street 3');
});

test('updateProfile overwrites', async () => {
  const user = await newUser({ givenName: 'Old', affiliation: 'Old Uni' });
  const updated = await admin().addUser({ email: user.email, givenName: 'New', affiliation: 'New Uni', updateProfile: true });
  assert.equal(updated.givenName, 'New');
  assert.equal(updated.affiliation, 'New Uni');
});

test('existing users get added roles; roles are not added twice', async () => {
  const user = await newUser();
  const contact = await admin().addUser({ email: user.email, roles: ['publisherContact'] });
  assert.deepEqual(contact._rolesAdded, ['publisherContact']);
  assert.deepEqual(contact.roles.sort(), ['author', 'publisherContact']);
  const again = await admin().addUser({ email: user.email, roles: ['publisherContact', 'author'] });
  assert.deepEqual(again._rolesAdded, []);
});

test('email lookup is case-insensitive', async () => {
  const user = await newUser();
  const found = await admin().addUser({ email: user.email.toUpperCase(), givenName: 'X' });
  assert.equal(found.id, user.id);
});

test('usernames are unique', async () => {
  const local = `e2esame${uid()}`;
  const first = await admin().addUser({ email: `${local}@one.example.org`, givenName: 'A' });
  const second = await admin().addUser({ email: `${local}@two.example.org`, givenName: 'B' });
  assert.notEqual(first.username, second.username);
  assert.ok(second.username.startsWith(first.username.slice(0, 20)));
});

test('list by role and find by email', async () => {
  const contact = await newUser({ roles: ['publisherContact'] });
  const contacts = await admin().listUsers({ role: 'publisherContact' });
  assert.ok(contacts.some((user) => user.id === contact.id));
  assert.ok(contacts.every((user) => user.roles.includes('publisherContact')));

  assert.equal((await admin().findUserByEmail(contact.email)).id, contact.id);
  assert.equal(await admin().findUserByEmail(`nobody-${uid()}@example.org`), null);
  assert.deepEqual(await admin().listUsers({ email: contact.email, role: 'bookReviewEditor' }), [], 'email and role combined');
  await rejects(admin().listUsers({ role: 'reviewer' }), 400, 'invalidRole');
});
