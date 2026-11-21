// Offline tests of the client with a mocked fetch: npm test (node --test)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ObjectsForReviewClient, OfrApiError, identifierInput, isDoi } from '../src/client.js';

/** A fetch that answers from a list of [method, path, status, body] and records the requests */
function mockFetch(routes) {
  const calls = [];
  const fetch = async (url, init) => {
    const u = new URL(url);
    const path = u.pathname.replace(/^.*\/api\/v1\/objectsForReview/, '');
    calls.push({ method: init.method, path, query: Object.fromEntries(u.searchParams), headers: init.headers,
      body: init.body ? JSON.parse(init.body) : undefined });
    const route = routes.find(([method, p]) => method === init.method && (typeof p === 'string' ? p === path : p.test(path)));
    const [, , status, body] = route ?? [null, null, 404, { error: 'notFound' }];
    const payload = typeof body === 'function' ? body(calls.at(-1)) : body;
    return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json' } });
  };
  return { fetch, calls };
}

const client = (fetch, extra = {}) => new ObjectsForReviewClient({ url: 'https://ojs.example/index.php/j/', token: 'T', fetch, ...extra });

test('identifiers: DOIs in any form, everything else is an ISBN', () => {
  assert.ok(isDoi('10.1515/9783110732870'));
  assert.ok(isDoi('https://doi.org/10.1515/x'));
  assert.ok(isDoi('doi:10.1515/x'));
  assert.ok(!isDoi('978-3-16-148410-0'));
  assert.deepEqual(identifierInput(' 978-0-262-04630-5 '), { isbn: '978-0-262-04630-5' });
});

test('builds the API URL and sends the token as Bearer header or query parameter', async () => {
  const { fetch, calls } = mockFetch([['GET', '/objects', 200, { items: [] }]]);
  await client(fetch).listObjects();
  assert.equal(calls[0].headers.Authorization, 'Bearer T');
  await client(fetch, { tokenInQuery: true }).listObjects();
  assert.equal(calls[1].query.apiToken, 'T');
  assert.equal(calls[1].headers.Authorization, undefined);
  assert.equal(new ObjectsForReviewClient({ url: 'https://x/index.php/j/api/v1/objectsForReview', token: 'T' }).apiUrl,
    'https://x/index.php/j/api/v1/objectsForReview');
});

test('errors carry status and code', async () => {
  const { fetch } = mockFetch([['POST', '/objects', 409, { error: 'duplicateIdentifier', errorMessage: 'exists', objectId: 3 }]]);
  await assert.rejects(client(fetch).addBook('10.1/x'), (error) => {
    assert.ok(error instanceof OfrApiError);
    assert.equal(error.status, 409);
    assert.equal(error.code, 'duplicateIdentifier');
    assert.equal(error.body.objectId, 3);
    return true;
  });
});

test('importData: users first, then works with their assignments; failures do not stop the import', async () => {
  let nextId = 1;
  const { fetch, calls } = mockFetch([
    ['POST', '/users', 200, ({ body }) => ({ id: nextId++, email: body.email, _created: true, _rolesAdded: body.roles ?? ['author'] })],
    ['POST', '/objects', 200, ({ body }) => (body.isbn === 'bad'
      ? undefined : { id: 10, identifier: body.isbn ?? body.doi, title: 'T', _existing: body.doi === '10.1/old' })],
    ['POST', /^\/objects\/\d+\/assignments$/, 200, ({ body }) => ({ id: 100, userId: 1, status: body.status })],
  ]);
  // A failing work: the mock answers 200 with no body for 'bad', so make it fail explicitly
  const failing = async (url, init) => (init.body?.includes('"isbn":"bad"')
    ? new Response(JSON.stringify({ error: 'invalidIsbn', errorMessage: 'Not a valid ISBN.' }), { status: 400 })
    : fetch(url, init));

  const report = await client(failing).importData({
    users: [{ email: 'p@x.org', givenName: 'P', roles: ['publisherContact'] }],
    objects: [
      { isbn: '9780262046305', assignments: [{ email: 'r@x.org', status: 'accepted', dueDate: '2027-01-01' }] },
      { isbn: 'bad', assignments: [{ email: 'r@x.org', status: 'bid' }] },
      { doi: '10.1/old', assignments: [{ email: 'r@x.org', status: 'bid' }] },
    ],
  });

  assert.equal(calls[0].path, '/users');
  assert.equal(calls[1].body.onDuplicate, 'skip');
  assert.deepEqual(report.users.map((u) => u.action), ['created']);
  assert.deepEqual(report.objects.map((o) => o.action), ['created', 'exists']);
  assert.deepEqual(report.assignments.map((a) => a.action), ['created', 'skipped (work existed)']);
  assert.deepEqual(report.errors.map((e) => [e.kind, e.code]), [['object', 'invalidIsbn'], ['assignment', null]]);
});

test('importData dry run changes nothing', async () => {
  const { fetch, calls } = mockFetch([
    ['GET', '/users', 200, { items: [] }],
    ['GET', '/objects', 200, { items: [] }],
    ['GET', '/lookup', 200, { identifier: '9780262046305', title: 'Found' }],
  ]);
  const report = await client(fetch).importData({
    users: [{ email: 'p@x.org' }],
    objects: [{ isbn: '978-0-262-04630-5', assignments: [{ email: 'r@x.org', status: 'bid' }] }],
  }, { dryRun: true });
  assert.ok(calls.every((call) => call.method === 'GET'));
  assert.equal(report.objects[0].title, 'Found');
  assert.equal(report.assignments[0].action, 'create');
});
