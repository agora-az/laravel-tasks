@extends('layouts.app')

@section('title', $metadata['title'])

@section('content')
<div class="docs-page">
    <div class="docs-breadcrumbs">
        <a href="{{ route('docs.index') }}">Documentation</a>
        <span aria-hidden="true">/</span>
        <span>{{ $metadata['title'] }}</span>
    </div>

    <div class="docs-reader">
        <aside class="docs-sidebar" aria-label="Documentation navigation">
            <div class="docs-sidebar-title">Documents</div>
            <nav>
                @foreach($categories as $categoryKey => $category)
                    <div class="docs-sidebar-group">
                        <div class="docs-sidebar-group-title docs-sidebar-group-title-{{ $categoryKey }}">
                            {{ $category['label'] }}
                        </div>
                        @foreach($documents as $slug => $registeredDocument)
                            @if($registeredDocument['category'] === $categoryKey)
                                <a href="{{ route('docs.show', $slug) }}" @class([
                                    'docs-sidebar-link-' . $categoryKey,
                                    'is-active' => $slug === $document,
                                ])>
                                    {{ $registeredDocument['title'] }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </nav>
        </aside>

        <article class="docs-article markdown-body">
            <div class="docs-article-audience">
                <span class="docs-category-badge docs-category-badge-{{ $metadata['category'] }}">
                    {{ $categories[$metadata['category']]['label'] }}
                </span>
            </div>
            {!! $content !!}
        </article>
    </div>
</div>
@endsection