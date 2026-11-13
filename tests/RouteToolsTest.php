<?php

namespace Routewise\Tests;

use Routewise\Routewise;
use Routewise\DefinedTool;
use Routewise\Tests\Fixtures\PostController;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;

class RouteToolsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::middleware(Authenticate::class)->prefix('api')->group(function () {
            Route::get('posts', [PostController::class, 'index'])->name('posts.index');
            Route::post('posts', [PostController::class, 'store'])->name('posts.store');
            Route::get('posts/{post_id}', [PostController::class, 'show'])->name('posts.show');
            Route::delete('posts/{post_id}', [PostController::class, 'destroy'])->name('posts.destroy');
            Route::post('posts/{post_id}/publish', [PostController::class, 'publish']);
            Route::get('secret', [PostController::class, 'secret']);
            Route::get('internal/stats', fn () => ['n' => 1]);
        });
        Route::get('dashboard', fn () => 'html');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('routewise.routes', ['enabled' => true, 'include' => ['api/*'], 'exclude' => ['api/internal/*'], 'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']]);
    }

    /** @return array<string, DefinedTool> */
    private function tools(): array
    {
        return collect(Routewise::tools())->keyBy(fn (DefinedTool $t) => $t->name())->all();
    }

    private function signIn(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 42]));
    }

    public function test_matching_routes_become_tools_with_kinds_names_and_descriptions(): void
    {
        $tools = $this->tools();

        $this->assertEqualsCanonicalizing(['posts_index', 'posts_store', 'posts_show', 'posts_destroy', 'posts_publish'], array_keys($tools));
        $this->assertSame(['read', 'write', 'read', 'delete', 'write'], array_map(fn ($n) => $tools[$n]->kind(), ['posts_index', 'posts_store', 'posts_show', 'posts_destroy', 'posts_publish']));
        $this->assertStringStartsWith('List posts, newest first.', $tools['posts_index']->description());
        $this->assertSame('Create a post as a draft or published.', $tools['posts_store']->description());
        $this->assertSame('GET api/posts/{post_id}', $tools['posts_show']->description());
    }

    public function test_arguments_come_from_the_path_and_the_form_request(): void
    {
        $tools = $this->tools();
        $fields = array_map(fn ($f) => $f->toArray(), $tools['posts_store']->fields());

        $this->assertSame(['title', 'status', 'views', 'tags', 'published_on'], array_keys($fields));
        $this->assertSame(['type' => 'string', 'required' => true], $fields['title']);
        $this->assertSame(['draft', 'published'], $fields['status']['enum']);
        $this->assertSame(['type' => 'integer', 'min' => 0, 'max' => 1000], $fields['views']);
        $this->assertSame(['type' => 'array', 'items' => 'integer'], $fields['tags']);
        $this->assertSame('date', $fields['published_on']['type']);
        $this->assertTrue($fields['published_on']['nullable']);

        $this->assertSame(['type' => 'integer', 'required' => true, 'min' => 1], $tools['posts_show']->fields()['post_id']->toArray());
        $this->assertSame('The post to publish', $tools['posts_publish']->fields()['post_id']->toArray()['description']);
    }

    public function test_a_call_runs_the_route_as_the_signed_in_user(): void
    {
        $this->signIn();
        $tools = $this->tools();
        $before = app('request');

        $this->assertSame([true, ['user' => 42, 'page' => null, 'posts' => [['id' => 1]]]], $this->invoke($tools['posts_index']));
        $this->assertSame([true, ['id' => 9]], $this->invoke($tools['posts_show'], ['post_id' => 9]));
        $this->assertSame([true, ['id' => 7, 'title' => 'Hi', 'status' => 'draft', 'tags' => [1, 2]]], $this->invoke($tools['posts_store'], ['title' => 'Hi', 'status' => 'draft', 'tags' => [1, 2]]));
        $this->assertSame([true, ['ok' => true, 'status' => 204]], $this->invoke($tools['posts_destroy'], ['post_id' => 3]));
        $this->assertSame([true, ['id' => 3, 'status' => 'published']], $this->invoke($tools['posts_publish'], ['post_id' => 3]));
        $this->assertSame($before, app('request'));
    }

    public function test_the_routes_own_checks_still_apply(): void
    {
        $tools = $this->tools();
        $this->assertSame([false, 'Unauthenticated.'], $this->invoke($tools['posts_index']));

        $this->signIn();
        $this->assertSame([false, 'No such post.'], $this->invoke($tools['posts_show'], ['post_id' => 404]));
        [$ok, $error] = $this->invoke($tools['posts_store'], ['title' => str_repeat('x', 200), 'status' => 'draft']);
        $this->assertFalse($ok);
        $this->assertStringContainsString('120 characters', $error);
    }

    public function test_routes_are_not_listed_unless_enabled_and_registered_sets_win(): void
    {
        Routewise::register(fn () => [DefinedTool::make('posts_index', 'read', 'Mine.', [], fn () => 'mine')]);
        $this->assertSame('Mine.', $this->tools()['posts_index']->description());

        config(['routewise.routes.enabled' => false]);
        $this->assertSame(['posts_index'], array_keys($this->tools()));
    }
}
