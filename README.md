# RAG (Retrieval-Augmented Generation) for EGroupware

## Features (planned or already implemented)
- [x] stores embeddings for all supported applications in table `egw_rag`
- [x] stores a fulltext index for all supported applications in table `egw_rag_fulltext`
- [x] create AND keep the above indexes up-to-date when entries are added, updated or deleted
- [x] provides search primitives applications can use to provide:
  * `Rag\Embeddings::searchEmbeddings()` a semantic search by given search-pattern
  * `Rag\Embeddings::searchFulltext()` a fulltext search either in [natural language mode](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/full-text-indexes/full-text-index-overview#in-natural-language-mode) or in [boolean mode](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/full-text-indexes/full-text-index-overview#in-boolean-mode), if operators are used (the default preference to add an `*` after each word, also swiches automatically to boolean mode!)
  * `Rag\Embeddings::search()` hybrid search combining semantic and fulltext search
  * the primitives return an array with ID => distance or relevance score of the embeddings or fulltext index
 - [x] UI for an application independent search 
  * show a (bigger) search-field
  * show a (multiple) application selection, default all supported apps
  * show a NM list with link-title, app-name and ID and onclick action to edit the entry
  * replace old EPL search in avatar-menu with RAG, if available
- [x] new RAG preference what to use in default search, if RAG is available (implementation in Api\Storage):
  * fulltext search (default)
  * hybrid search (if RAG/Embedding is configured)
  * semantic search / RAG only
  * legacy search of the apps
  * search will still support #<number> to find the ID
  * apps can prefer/overwrite the above, e.g. Addressbook to use its own phone-number search
- [x] fulltext search supports [boolean search operators](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/full-text-indexes/full-text-index-overview#in-boolean-mode)
- [x] preference to automatically append an asterisk (*) after each search pattern, which is not enclosed in quotes ("), to also match words beginning with the pattern
> Please note:
>
> the fulltext index does NOT find patterns within a word, only complete words or words starting with the pattern,
> if the above preference is set (by default), or the `*` operator is added manually after the pattern
> e.g. `some*` will also find `something`, but not `awesome`!
- [x] preference to keep sort-order from apps (default), or use relevance from RAG/fulltext search, when search in the apps
- [x] UI of RAG allows sorting the search result by distance&relevance (default), modification date, distance or relevance alone
- [x] change search in filterbox, to include:
  * selection which search-type to use incl. legacy search
  * fulltext operator help (like in RAG UI)
- [x] cache embeddings of the search-pattern to not always have to request them again
- [x] store sha256 of chunks, to not regenerate embeddings of unchanged chunks
- [ ] support other embedding models AND modify schema to support its number of dimensions (the latter is not yet implemented!)
- [ ] make the results of the above searches available to AI agents / integrate with them

## Supported applications
- [x] InfoLog
- [x] Tracker
- [x] allow app-plugins in apps namespace `EGroupware\<application>\Rag` instead directly in RAG's `EGroupware\Rag\Embeddings\<application>`
- [x] Addressbook, Calendar, Timesheet, ProjectManager and Phpbrain (aka KnowledgeBase)
- [ ] other egroupware applications: Invoices, Resources, Records, ViDoTeach, ...

## Requirements and installation
* MariaDB 11.8 for the required vector type to store and search for embeddings.
  Add the following to your docker-compose.override.yml to use MariaDB 11.8 (also works with EGroupware 23.1)
```
# /etc/egroupware-docker/docker-compose.override.yml
service:
  db:
    image: mariadb:11.8
    environment:
    - MARIADB_AUTO_UPGRADE=true
```
> The above will happen automatic with egroupware-docker-26 package (once it's released).
* [bge-m3](https://ollama.com/library/bge-m3) Embedding Model via either:
  - an [Ollama installation](https://ollama.com/blog/ollama-is-now-available-as-an-official-docker-image) with bge-m3 installed: `ollama pull bge-m3:latest`
  - or an OpenAI compatible endpoint and API key to access bge-m3 e.g. via IONOS AI Hub
* Make sure ollama is [reachable](https://docs.ollama.com/faq#how-can-i-expose-ollama-on-my-network) from the RAG container, by default it binds to 127.0.0.1:11434
* RAG application repo must be cloned into the EGroupware source directory (`/usr/share/egroupware` in an on-premise installation)
> RAG application will be delivered with regular EGroupware 26.x container images from next pre-release on.
* the RAG app then needs to be configured: `Admin > Applications > RAG > App Configuration`, by default it only does the fulltext index

## App Configuration
### General Configuration

* URL and optional API key for the [OpenAI compatible endpoint](https://docs.ollama.com/api/openai-compatibility)
* Add "/v1/" to your Ollama URL to access the OpenAI compatible endpoint
* (optional, without RAG just uses/creates the fulltext index)
> Ollama: you can NOT use the default localhost in an other container, use e.g. the docker0 address 172.17.0.1!
### Embedding Configuration
* name of an embedding model to use, defaults to `bge-m3`
> Currently 1024 dimensions are hard-coded in the schema, you need to change the schema manually for a different value!
* chunk-size and overlap for chunking the texts of the application entries (default is 500 and 50 chars)
* setting to minimize chunks by concatenating all texts (before splitting them into chunks), instead of chunk-splitting them all separate
> Calculating and storing Embeddings, and to much lower extend also the Fulltext index, costs a not to diminishing amount of storage for the tokens!

## Usage for the supported apps
* RAG search over all (supported) application is now shown in the avatar menu under `search`
* supported apps use by default the fulltext index for searching 
* the above can be changed permanently via a preference or 
* through the small RAG user-interface show in the filterbox
