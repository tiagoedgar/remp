<?php

namespace Tests\Feature;

use App\Account;
use App\Article;
use App\ArticleAggregatedView;
use App\Console\Commands\AggregateArticlesViews;
use App\Property;
use Mockery;
use Remp\Journal\Journal;
use Remp\Journal\JournalContract;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AggregateArticlesViewsTest extends TestCase
{
    use RefreshDatabase;

    public function testJob()
    {
        $pageviews1 = <<<JSON
[
    {
        "count": 8,
        "tags": {
            "article_id": "2148518",
            "user_id": "1234",
            "browser_id": "abcd"
        }
    },
    {
        "count": 3,
        "tags": {
            "article_id": "1148518",
            "user_id": "1234",
            "browser_id": "abcd"
        }
    }
]
JSON;
        $pageviews2 = <<<JSON
[
    {
        "count": 3,
        "tags": {
            "article_id": "1148518",
            "user_id": "1234",
            "browser_id": "abcd"
        }
    }
]
JSON;
        $pageviews3 = '[]';

        $timespent1 = <<<JSON
[
    {
        "sum": 10,
        "tags": {
            "article_id": "2148518",
            "user_id": "1234",
            "browser_id": "abcd"
        }
    }
]
JSON;
        $timespent2 = <<<JSON
[
    {
        "sum": 25,
        "tags": {
            "article_id": "2148518",
            "user_id": "1234",
            "browser_id": "abcd"
        }
    }
]
JSON;
        $timespent3 = '[]';

        $account = factory(Account::class)->create();
        $property = factory(Property::class)->create(['account_id' => $account->id]);
        $article1148518 = factory(Article::class)->create([
            'external_id' => 1148518,
            'property_uuid' => $property->uuid,
        ]);
        $article2148518 = factory(Article::class)->create([
            'external_id' => 2148518,
            'property_uuid' => $property->uuid,
        ]);

        // Mock Journal data
        // job aggregates pageviews day data in time windows
        $journalMock = Mockery::mock(Journal::class);
        $journalMock->shouldReceive('count')->andReturn(
            json_decode($pageviews1),
            json_decode($pageviews2),
            json_decode($pageviews3)
        );

        // job aggregates timespent day data in time windows
        $journalMock->shouldReceive('sum')->andReturn(
            json_decode($timespent1),
            json_decode($timespent2),
            json_decode($timespent3)
        );

        // Bypass RempJournalServiceProvider binding
        $this->app->instance('mockJournal', $journalMock);
        $this->app->when(AggregateArticlesViews::class)
            ->needs(JournalContract::class)
            ->give('mockJournal');

        $this->artisan(AggregateArticlesViews::COMMAND);

        $articleView2148518 = ArticleAggregatedView::where([
            'article_id' => $article2148518->id,
            'user_id' => '1234'
        ])->first();

        $this->assertEquals(8, $articleView2148518->pageviews);
        $this->assertEquals(35, $articleView2148518->timespent);

        $articleView1148518 = ArticleAggregatedView::where([
            'article_id' => $article1148518->id,
            'user_id' => '1234'
        ])->first();

        $this->assertEquals(6, $articleView1148518->pageviews);
        $this->assertEquals(0, $articleView1148518->timespent);

        // Test when aggregation is run twice (and no aggregated data is returned), former data is deleted
        $this->artisan(AggregateArticlesViews::COMMAND);

        $this->assertEquals(0, ArticleAggregatedView::all()->count());
    }
}
