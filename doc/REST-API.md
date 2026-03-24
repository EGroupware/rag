# EGroupware REST API for RAG

Authentication is via Basic Auth with username and a password, or a token valid for:
- either just the given user or all users
- CalDAV/CardDAV Sync (REST API)
- RAG application

All URLs used in this document are relative to EGroupware's REST API URL:
`https://egw.example.org/egroupware/groupdav.php/`

That means instead of `/rag/` you have to use the full URL `https://egw.example.org/egroupware/groupdav.php/rag/` replacing `https://egw.example.org/egroupware/` with whatever URL your EGroupware installation uses. 

### Hierarchy

`/rag`  application collection (`GET` request to search)

### State of the REST API implementation
- [x] search the RAG via GET requests
> Inserting or updating documents happens via the apps own REST API or EGroupware's UI

### Supported request methods and examples

> `GET` requests require an `Accept: application/json` or `Accept: application/pretty+json` header.

The GET parameter `filters` allows to search for a pattern in the RAG:
- **required** `filters[search]=<pattern>` searches for `<pattern>` in the RAG like the search in the GUI
- `filters[apps][]=<app-name>` limit search to given app-name(s), can be specified multiple times
- `filters[type]`= `hybrid` | `fulltext` | `rag` what type of search to use, default `hybrid`
- `filters[order]`= (`default` | `distance` | `relevance` | `modified`) (`ASC` | `DESC`), default `default ASC`

> GET parameter `filters[search]=<pattern>` is **required** and must be at least 3 chars, you get an error otherwise!

Following GET parameters are supported to customize the returned properties:
- `props[]=<DAV-prop-name>` e.g. `props[]=displayname` to return only the name (multiple DAV properties can be specified).
- ~~sync-token=<token> to only request change since last sync-token, like rfc6578 sync-collection REPORT~~ (not yet supported)
- ~~nresults=N limit number of responses (only for sync-collection / given sync-token parameter!)
  this will return a "more-results"=true attribute and a new "sync-token" attribute to query for the next chunk~~


<details>
   <summary>Example: Getting just (display-)name of all matching RAG entries</summary>

```
curl -i 'https://example.org/egroupware/groupdav.php/rag/?filters[search]=test&props[]=displayname' -H "Accept: application/pretty+json" --user <username>

{
  "responses": {
    "/rag/addressbook:123": "Test.org: Testen, Tester",
    "/rag/calendar:345": "18.04.2025, 10:00: Test & Testen",
    ...
  }
}
```
</details>

<details>
   <summary>Example: Getting the full date of all matching RAG entries</summary>

```
curl -i 'https://example.org/egroupware/groupdav.php/rag/?filters[search]=test' -H "Accept: application/pretty+json" --user <username>

{
  "responses": {
    "/rag/addressbook:123": {
        "modified": "2015-09-04T09:20:59Z",
        "relevance": 11.602869987487793,
        "title": "Test.org: Testen, Tester",
        "description": null,
        "extra": [
            "Testen",
            "Tester",
            "Test Test",
            "Test.org"
        ],
        "id": "addressbook:123",
        "app": "addressbook",
        "app_id": "123"
    },
    "/rag/calendar:345": {
        "modified": "2025-10-10T08:27:27Z",
        "relevance": 5.8014349937438965,
        "title": "18.04.2025, 10:00: Test & Testen",
        "description": null,
        "extra": [],
        "id": "calendar:345",
        "app": "calendar",
        "app_id": "345"
    }    
    ...
  }
}
```
</details>

### Schema used for RAG entries

- `modified` last modified timestamp in UTC e.g. "2025-10-10T08:27:27Z"
- `relevance` fulltext search relevance: as bigger as better the match is, only results with at least 5% of the highest match are returned
- `distance` semantic search distance: 0.0 100% match, 1.0 no match, -1.0 opposite, only results < 0.4 are returned
- `title` link-title of the entry
- `description` multiline text-field of the entry (e.g. note-field of the contact)
- `extra` array of further fulltext indexed text-fields
- `id` `<app-name>:<app-id>`
- `app` app-name of the entry
- `app_id` ID of entry in the entries app