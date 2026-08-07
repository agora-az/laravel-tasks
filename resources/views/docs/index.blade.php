@extends('layouts.app')

@section('title', 'Documentation')

@section('content')
<div class="docs-page">
    <div class="docs-page-header">
        <div>
            <h2>Documentation</h2>
            <p>Reference guides, report criteria, and VieFund integration notes.</p>
        </div>
    </div>

    @foreach($categories as $categoryKey => $category)
        <section class="docs-category-section docs-category-{{ $categoryKey }}">
            <div class="docs-category-heading">
                <span class="docs-category-badge docs-category-badge-{{ $categoryKey }}">{{ $category['label'] }}</span>
                <p>{{ $category['description'] }}</p>
            </div>

            <div class="docs-index-grid">
                @foreach($documents as $slug => $document)
                    @if($document['category'] === $categoryKey)
                        <a href="{{ route('docs.show', $slug) }}" class="docs-index-item docs-index-item-{{ $categoryKey }}">
                            <span class="docs-index-kicker">{{ $category['label'] }}</span>
                            <strong>{{ $document['title'] }}</strong>
                            <span>{{ $document['description'] }}</span>
                            <span class="docs-index-link">Open document <span aria-hidden="true">→</span></span>
                        </a>
                    @endif
                @endforeach
            </div>
        </section>
    @endforeach
</div>
@endsection
