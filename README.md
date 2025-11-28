# RAG (Retrieval-Augmented Generation) for EGroupware

## Features (planned or already implemented)
- [x] stores embeddings for all supported applications in table `egw_rag`
- [x] stores a fulltext index for all supported applications in table `egw_rag_fulltext`
- [x] create AND keep the above indexes up-to-date when entries are added, updated or deleted
- [x] provides search primitives applications can use to provide:
  * `Rag\Embeddings::searchEmbeddings()` a semantic search by given search-pattern
  * `Rag\Embeddings::searchFulltext()` a fulltext search currently only in [natural language mode](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/full-text-indexes/full-text-index-overview#in-natural-language-mode)
  * `Rag\Embeddings::search()` hybrid search combining semantic and fulltext search
  * the primitives return an array with ID => distance or relevance score of the embeddings or fulltext index
  * [InfoLog](https://github.com/EGroupware/egroupware/blob/28ac42ddf4cac584b1efded8bad5f787ae644e76/infolog/inc/class.infolog_bo.inc.php#L1427-L1439) and [Tracker](https://github.com/EGroupware/tracker/blob/f11e70ce7a6646bd3923ff5a25d52e6e05cca47b/inc/class.tracker_so.inc.php#L303-L310) uses these for a simple integration by prefixing the search pattern with `&` (an ampersand character) in the regular search
- [ ] improve search box `et2-search` allowing to specify what type of search to use in a nicer form, the prefixing the search-pattern with a `&`, and storing the decision in an implizit preference
- [ ] UI for an application independent search 
  * show a (bigger) search-field
  * show a (multiple) application selection, default all supported apps
  * show a NM list with link-title, appname and ID and onclick action to edit the entry
- [ ] support other embedding models AND modify schema to support its number of dimensions (the latter is not yet implemented!)
- [ ] make the results of the above searches available to AI agents / integrate with them

## Supported applications
- [x] InfoLog
- [x] Tracker
- [ ] all other egroupware applications
- [ ] move plugins to apps namespace `<application>\Rag` instead current `Rag\Embeddings\<application>` and detecting/finding them here

## Requirements and installation
* MariaDB 11.8 for the required vector type to store and search for embeddings
  - replace image in your docker-compose(.override).yml for `egroupware-db` service with `mariadb:11.8`
  - add `- MARIADB_AUTO_UPGRADE: "true"` to the environment variables of `egroupware-db` service
  - all the above will happen automatic with egroupware-docker-26 package (once it's released)
* [bge-m3](https://ollama.com/library/bge-m3) Embedding Model via
  - an [Ollama installation](https://ollama.com/blog/ollama-is-now-available-as-an-official-docker-image) with bge-m3 installed
  - on OpenAI compatible endpoint and API key to access bge-m3 e.g. via IONOS AI Hub
* RAG application repo must be cloned into the EGroupware source directory (`/usr/share/egroupware` in an onpremis installation)
> You currently need an account of or a deployment token for the EGroupwareGmbH organisation, as `rag` is currently (still) a private repo their!

## App Configuration
### General Configuration
* URL and optional API key for the OpenAI compatible endpoint
> Ollama you can NOT use the default localhost in an other container, use e.g. the docker0 address 172.17.0.1!
### Embedding Configuration
* name of an embedding model to use, defaults to `bge-m3`
> Currently 1024 dimensions are hard-coded in the schema, you need to change the schema manually for a different value!
* chunk-size and overlap for chunking the texts of the application entries (default is 500 and 50 chars)
* setting to minimize chunks by concatenating all texts (before splitting them into chunks), instead of chunk-splitting them all separate
### Planned further Configuration
- [ ] configure which type of index (RAG/Embeddings or Fulltext) to create and
- [ ] for which (supported) apps to create or not create the indexes
> Calculating and storing Embeddings, and to much lower extend also the Fulltext index, costs a not to diminishing amount of storage the tokes!