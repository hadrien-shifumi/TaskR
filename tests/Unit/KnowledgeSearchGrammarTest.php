<?php

namespace Tests\Unit;

use App\Actions\Knowledge\SearchKnowledge;
use App\Support\Embeddings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KnowledgeSearchGrammarTest extends TestCase
{
    public function test_pgsql_ranking_uses_cosine_operator_and_similarity_ordering(): void
    {
        $action = new class(app(Embeddings::class)) extends SearchKnowledge
        {
            public function __construct(Embeddings $embeddings)
            {
                parent::__construct($embeddings);
            }

            /**
             * @param  array<int, float>  $vector
             */
            public function exposePgsqlRankedQuery(array $vector): Builder
            {
                return $this->pgsqlRankedQuery($vector);
            }
        };

        $query = $action->exposePgsqlRankedQuery([0.1, 0.2, 0.3]);

        $sql = (new PostgresGrammar(DB::connection()))->compileSelect($query->getQuery());

        // The production path cannot run on the sqlite test suite: assert the
        // pgvector cosine operator and the similarity ordering at the grammar
        // level instead.
        $this->assertStringContainsString('embedding <=> ?::vector', $sql);
        $this->assertStringContainsString('1 - (embedding <=> ?::vector)', $sql);
    }
}
