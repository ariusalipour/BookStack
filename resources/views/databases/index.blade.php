@extends('layouts.simple')

@section('body')
    <div class="container">
        <main class="content-wrap">
            <div class="flex-container-row items-center justify-space-between mb-m">
                        <div class="flex">
                    <h1 class="list-heading">{{ trans('databases.title') }}</h1>
                    <p class="text-muted">{{ trans('databases.description') }}</p>
                </div>
            </div>

            @if ($databases->isEmpty())
                <div class="card p-m text-center">
                    <h2>{{ trans('databases.empty_title') }}</h2>
                    <p>{{ trans('databases.empty_description') }}</p>
                </div>
            @else
                <ul class="entity-list">
                    @foreach ($databases as $database)
                        <li class="entity-list-item">
                            <a href="{{ url('/databases/' . $database->id) }}" class="bold">{{ $database->name }}</a>
                            <span class="text-muted">{{ $database->book->name }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canCreate)
                <section class="card p-m mt-l" aria-labelledby="database-create-heading">
                    <h2 id="database-create-heading" class="list-heading">{{ trans('databases.create_title') }}</h2>
                    <form action="{{ url('/databases') }}" method="POST">
                        {{ csrf_field() }}
                        <div class="form-group">
                            <label for="database-name">{{ trans('common.name') }}</label>
                            <input class="form-control" id="database-name" type="text" name="name" value="{{ old('name') }}" required maxlength="255">
                            @include('form.errors', ['name' => 'name'])
                        </div>
                        <div class="form-group">
                            <label for="database-book">{{ trans('databases.book') }}</label>
                            <select class="form-control" id="database-book" name="book_id" required>
                                <option value="">{{ trans('databases.choose_book') }}</option>
                                @foreach ($books as $book)
                                    <option value="{{ $book->id }}" @if(old('book_id') == $book->id) selected @endif>{{ $book->name }}</option>
                                @endforeach
                            </select>
                            @include('form.errors', ['name' => 'book_id'])
                        </div>
                        <div class="text-right"><button type="submit" class="button">{{ trans('databases.create') }}</button></div>
                    </form>
                </section>
            @endif

            @if (!$canCreate && userCan(\BookStack\Permissions\Permission::BookCreateAll))
                <p class="mt-m"><a class="button outline" href="{{ url('/books/create') }}">{{ trans('databases.create_book') }}</a></p>
            @endif
        </main>
    </div>
@stop
