<?php

namespace AgentTools\Tests\Fixtures;

use AgentTools\Attributes\AgentTool;
use Illuminate\Http\Request;

class PostController
{
    #[AgentTool(description: 'List posts, newest first. Use it before creating one to avoid duplicates.')]
    public function index(Request $request)
    {
        return ['user' => $request->user()->id, 'page' => $request->query('page'), 'posts' => [['id' => 1]]];
    }

    /**
     * Create a post as a draft or published.
     *
     * @return array
     */
    public function store(StorePostRequest $request)
    {
        return response()->json(['id' => 7, ...$request->validated()], 201);
    }

    public function show(int $post_id)
    {
        abort_if($post_id === 404, 404, 'No such post.');

        return ['id' => $post_id];
    }

    public function destroy(int $post_id)
    {
        return response()->noContent();
    }

    #[AgentTool(hidden: true)]
    public function secret()
    {
        return ['secret' => true];
    }

    #[AgentTool(name: 'posts_publish', kind: 'write', description: 'Publish a draft post so readers can see it.', fields: ['post_id' => 'The post to publish'])]
    public function publish(int $post_id)
    {
        return ['id' => $post_id, 'status' => 'published'];
    }
}
