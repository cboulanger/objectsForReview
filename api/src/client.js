/**
 * Client for the REST API of the OJS Objects for Review plugin
 * (served by api/ObjectsForReviewController.php).
 *
 *   import { ObjectsForReviewClient } from './src/client.js';
 *   const ofr = new ObjectsForReviewClient({ url: 'https://example.org/index.php/journal', token: '...' });
 *   await ofr.addBook({ isbn: '978-3-16-148410-0' });
 *
 * No dependencies; needs Node 18 or later (global fetch).
 */

/** COAR resource type URIs accepted as resourceType */
export const RESOURCE_TYPES = {
  book: 'http://purl.org/coar/resource_type/c_2f33',
  bookPart: 'http://purl.org/coar/resource_type/c_3248',
  journalArticle: 'http://purl.org/coar/resource_type/c_6501',
  conferencePaper: 'http://purl.org/coar/resource_type/c_5794',
  thesis: 'http://purl.org/coar/resource_type/c_46ec',
  preprint: 'http://purl.org/coar/resource_type/c_816b',
  website: 'http://purl.org/coar/resource_type/c_7ad9',
  dataset: 'http://purl.org/coar/resource_type/c_ddb1',
  cartographicMaterial: 'http://purl.org/coar/resource_type/c_12cc',
  sound: 'http://purl.org/coar/resource_type/c_18cc',
};

export const ROLES = ['author', 'publisherContact', 'bookReviewEditor'];

export const ASSIGNMENT_STATUSES = ['bid', 'unsuccessful', 'offered', 'accepted', 'submitted', 'completed'];

export const COPY_STATUSES = ['notNeeded', 'pending', 'requested', 'sent', 'received', 'returnRequested', 'returnSent', 'returned'];

/** An error response of the API (or of the network). */
export class OfrApiError extends Error {
  constructor(status, body, method, path) {
    const message = body?.errorMessage || body?.error || (typeof body === 'string' ? body.slice(0, 200) : `HTTP ${status}`);
    super(`${method} ${path}: ${status} ${message}`);
    this.name = 'OfrApiError';
    this.status = status;
    this.code = body?.error ?? null;
    this.body = body;
  }
}

/**
 * Is this a DOI (bare, "doi:" prefixed or a doi.org URL)? Otherwise it is taken for an ISBN.
 */
export function isDoi(identifier) {
  return /^(doi:\s*|(https?:\/\/)?(dx\.)?doi\.org\/)?10\.\d{4,9}\//i.test(String(identifier).trim());
}

/**
 * { doi } or { isbn } for a DOI or ISBN given as a string.
 */
export function identifierInput(identifier) {
  const value = String(identifier).trim();
  return isDoi(value) ? { doi: value } : { isbn: value };
}

export class ObjectsForReviewClient {
  /**
   * @param {object} options
   * @param {string} options.url Journal URL, e.g. https://example.org/index.php/journal
   *   (or the API URL itself: .../api/v1/objectsForReview)
   * @param {string} options.token API key from the user profile (OJS: Profile › API Key)
   * @param {boolean} [options.tokenInQuery] Send the token as ?apiToken= instead of the
   *   Authorization header (for servers that strip the header)
   * @param {number} [options.timeout] Request timeout in ms (default 60000; lookups query Crossref/Open Library)
   * @param {typeof fetch} [options.fetch] fetch implementation (default: global fetch)
   */
  constructor({ url, token, tokenInQuery = false, timeout = 60000, fetch: fetchImpl } = {}) {
    if (!url) throw new Error('url is required (journal URL, e.g. https://example.org/index.php/journal)');
    if (!token) throw new Error('token is required (API key from the OJS user profile)');
    const base = url.replace(/\/+$/, '');
    this.apiUrl = /\/api\/v\d+\/objectsForReview$/.test(base) ? base : `${base}/api/v1/objectsForReview`;
    this.token = token;
    this.tokenInQuery = tokenInQuery;
    this.timeout = timeout;
    this.fetch = fetchImpl ?? globalThis.fetch;
  }

  /**
   * Send a request; resolves to the parsed JSON body, rejects with OfrApiError.
   */
  async request(method, path, { query, body } = {}) {
    const url = new URL(this.apiUrl + path);
    for (const [key, value] of Object.entries(query ?? {})) {
      if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
    }
    const headers = { Accept: 'application/json' };
    if (this.tokenInQuery) {
      url.searchParams.set('apiToken', this.token);
    } else {
      headers.Authorization = `Bearer ${this.token}`;
    }
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    let response;
    try {
      response = await this.fetch(url, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(this.timeout),
      });
    } catch (error) {
      throw new OfrApiError(0, { error: 'networkError', errorMessage: error.message }, method, path);
    }
    const text = await response.text();
    let data;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      data = text;
    }
    if (!response.ok) {
      const error = new OfrApiError(response.status, data, method, path);
      // A rejected or missing token (not a role without access): the header may not have arrived
      if (response.status === 401 && !this.tokenInQuery && !String(data?.error ?? '').startsWith('user.authorization.')) {
        error.message += ' (if the token is valid, the server may strip the Authorization header: try tokenInQuery / --token-in-query)';
      }
      throw error;
    }
    return data;
  }

  //
  // Works
  //

  /** Metadata for a DOI or ISBN from Crossref / Open Library; nothing is saved. */
  lookup({ doi, isbn }) {
    return this.request('GET', '/lookup', { query: { doi, isbn } });
  }

  /** All works of the journal, without their assignments. */
  async listObjects() {
    return (await this.request('GET', '/objects')).items;
  }

  /**
   * The work with this identifier, or null. DOIs and ISBNs are compared normalized.
   * @param {{doi?: string, isbn?: string, identifier?: string, identifierType?: string}} id
   */
  async findObject({ doi, isbn, identifier, identifierType }) {
    const query = doi ? { identifier: doi, identifierType: 'doi' }
      : isbn ? { identifier: isbn, identifierType: 'isbn' }
        : { identifier, identifierType };
    const { items } = await this.request('GET', '/objects', { query });
    return items[0] ?? null;
  }

  /** A work with its assignments. */
  getObject(objectId) {
    return this.request('GET', `/objects/${Number(objectId)}`);
  }

  /**
   * Create a work offered for review. With doi or isbn, the metadata is looked up and
   * fields given here override it. Without, identifier, identifierType and title are required.
   * Fields: doi, isbn, identifier, identifierType, resourceType, title, authors, publisher,
   * year, description, assignmentMode (direct|bidding), bidDeadline, responsibleEditorId |
   * responsibleEditorEmail, publisherContactId | publisherContactEmail, journalHoldsCopy,
   * returnRequired, notifyPublisher, hidden, dateCreated, lookup (false: no lookup),
   * onDuplicate (error|skip|update; default error).
   * The result has _existing: true if the work was there already (skip/update).
   */
  addObject(data) {
    return this.request('POST', '/objects', { body: data });
  }

  /** addObject() for a DOI or ISBN given as a string. */
  addBook(identifierOrData, extra = {}) {
    const data = typeof identifierOrData === 'string' ? identifierInput(identifierOrData) : identifierOrData;
    return this.addObject({ ...data, ...extra });
  }

  /** Change fields of a work (only those given; same fields as addObject). */
  updateObject(objectId, data) {
    return this.request('PUT', `/objects/${Number(objectId)}`, { body: data });
  }

  //
  // Review processes
  //

  listAssignments(objectId) {
    return this.request('GET', `/objects/${Number(objectId)}/assignments`);
  }

  /**
   * Import a bidder or reviewer whose review process is already under way. No emails are sent.
   * Fields: userId | email (+ givenName, familyName, affiliation to create the account),
   * status (bid|unsuccessful|offered|accepted|submitted|completed), dateCreated, dateStatus,
   * statement (bids), contractDeadline, dateOffered (offered), dueDate (required for accepted),
   * copyPreference (have|physical|electronic|other), copyNote, shippingAddress, phone,
   * copyStatus, copyStatusDate, copySentBy, returnTarget, submissionId (submitted/completed).
   */
  addAssignment(objectId, data) {
    return this.request('POST', `/objects/${Number(objectId)}/assignments`, { body: data });
  }

  //
  // People
  //

  /** Users of the journal, optionally only those with a role, or the one with an email. */
  async listUsers({ role, email } = {}) {
    return (await this.request('GET', '/users', { query: { role, email } })).items;
  }

  async findUserByEmail(email) {
    return (await this.listUsers({ email }))[0] ?? null;
  }

  /**
   * Find a user by email or create one (no email is sent, the password is random), and give
   * them roles. Fields: email, givenName (required to create), familyName, affiliation,
   * mailingAddress, phone, country (ISO code), username, roles (author|publisherContact|
   * bookReviewEditor; default author), updateProfile (overwrite existing profile fields).
   * The result has _created and _rolesAdded.
   */
  addUser(data) {
    return this.request('POST', '/users', { body: data });
  }

  /** A reviewer (review author): a user in the journal's Author group. */
  addAuthor(data) {
    return this.addUser({ ...data, roles: ['author'] });
  }

  /** A person at a publisher, who confirms review copy steps by one-click links. */
  addPublisherContact(data) {
    return this.addUser({ ...data, roles: ['publisherContact'] });
  }

  //
  // Bulk import
  //

  /**
   * Import people, works and review processes from one document (see examples/import.example.json):
   *
   *   { "users": [ { email, givenName, ..., roles } ],
   *     "objects": [ { doi | isbn | identifier..., ..., "assignments": [ { email, status, ... } ] } ] }
   *
   * Users come first, so works can refer to publisher contacts and editors by email.
   * Each record is imported on its own: failures are reported and the rest continues.
   * Assignments of a work that was there already are skipped unless importAssignmentsOfExisting is set.
   *
   * @param {object} doc
   * @param {object} [options]
   * @param {'error'|'skip'|'update'} [options.onDuplicate='skip'] Works with an identifier that exists
   * @param {boolean} [options.importAssignmentsOfExisting=false]
   * @param {boolean} [options.dryRun=false] Only look up identifiers and existing records; change nothing
   * @param {(event: object) => void} [options.onProgress]
   * @returns {Promise<{users: object[], objects: object[], assignments: object[], errors: object[]}>}
   */
  async importData(doc, { onDuplicate = 'skip', importAssignmentsOfExisting = false, dryRun = false, onProgress = () => {} } = {}) {
    const report = { users: [], objects: [], assignments: [], errors: [] };
    const fail = (kind, record, error) => {
      const entry = { kind, record: summarize(record), status: error.status ?? null, code: error.code ?? null, message: error.message };
      report.errors.push(entry);
      onProgress({ type: 'error', ...entry });
    };

    for (const user of doc.users ?? []) {
      try {
        let result;
        if (dryRun) {
          const existing = await this.findUserByEmail(user.email);
          result = { email: user.email, action: existing ? 'exists' : 'create', id: existing?.id ?? null };
        } else {
          const saved = await this.addUser(user);
          result = { email: saved.email, id: saved.id, action: saved._created ? 'created' : 'exists', rolesAdded: saved._rolesAdded };
        }
        report.users.push(result);
        onProgress({ type: 'user', ...result });
      } catch (error) {
        fail('user', user, error);
      }
    }

    for (const record of doc.objects ?? []) {
      const { assignments = [], ...objectData } = record;
      let object;
      let existed = false;
      try {
        if (dryRun) {
          const existing = await this.findObject(objectData);
          const metadata = !existing && (objectData.doi || objectData.isbn) && objectData.lookup !== false
            ? await this.lookup(objectData) : null;
          object = existing;
          existed = Boolean(existing);
          const result = { id: existing?.id ?? null, identifier: existing?.identifier ?? metadata?.identifier ?? objectData.identifier,
            title: objectData.title ?? existing?.title ?? metadata?.title, action: existing ? `exists (${onDuplicate})` : 'create' };
          report.objects.push(result);
          onProgress({ type: 'object', ...result });
        } else {
          object = await this.addObject({ ...objectData, onDuplicate: objectData.onDuplicate ?? onDuplicate });
          existed = Boolean(object._existing);
          const result = { id: object.id, identifier: object.identifier, title: object.title,
            action: existed ? (onDuplicate === 'update' ? 'updated' : 'exists') : 'created' };
          report.objects.push(result);
          onProgress({ type: 'object', ...result });
        }
      } catch (error) {
        fail('object', objectData, error);
        for (const assignment of assignments) {
          fail('assignment', assignment, { message: 'skipped: its work could not be imported' });
        }
        continue;
      }

      for (const assignment of assignments) {
        const label = { objectId: object?.id ?? null, email: assignment.email ?? null, userId: assignment.userId ?? null, status: assignment.status };
        if (existed && !importAssignmentsOfExisting) {
          report.assignments.push({ ...label, action: 'skipped (work existed)' });
          onProgress({ type: 'assignment', ...label, action: 'skipped (work existed)' });
          continue;
        }
        if (dryRun) {
          report.assignments.push({ ...label, action: 'create' });
          onProgress({ type: 'assignment', ...label, action: 'create' });
          continue;
        }
        try {
          const saved = await this.addAssignment(object.id, assignment);
          const result = { ...label, id: saved.id, userId: saved.userId, action: 'created' };
          report.assignments.push(result);
          onProgress({ type: 'assignment', ...result });
        } catch (error) {
          fail('assignment', { objectId: object.id, ...assignment }, error);
        }
      }
    }
    return report;
  }
}

/** A short label for a record in error reports (no addresses or phone numbers). */
function summarize(record) {
  const keys = ['objectId', 'doi', 'isbn', 'identifier', 'title', 'email', 'userId', 'status'];
  return Object.fromEntries(keys.filter((key) => record?.[key] !== undefined).map((key) => [key, record[key]]));
}
