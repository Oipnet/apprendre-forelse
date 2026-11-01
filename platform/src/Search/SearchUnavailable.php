<?php

namespace App\Search;

/** Meilisearch ne répond pas, ou refuse la requête : la page de recherche le dit, l'indexation réessaiera. */
final class SearchUnavailable extends \RuntimeException
{
}
