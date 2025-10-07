<?php

return [
    // TTL (segundos) do cache de listagem de contatos (microcache)
    'contacts_index_cache_ttl' => (int) env('CONTACTS_INDEX_CACHE_TTL', 5),

    // TTL (segundos) do cache de estatísticas de contatos
    'contacts_stats_cache_ttl' => (int) env('CONTACTS_STATS_CACHE_TTL', 10),

    // Habilita uso de FULLTEXT (MySQL/MariaDB) para buscas quando disponível
    'contacts_search_fulltext' => (bool) env('CONTACTS_SEARCH_FULLTEXT', false),
];
