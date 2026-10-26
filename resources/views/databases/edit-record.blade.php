@extends('layouts.simple')

@section('title', trans('databases.edit_record'))

@section('body')
    <div class="container small">
        <div class="my-s">@include('entities.breadcrumbs', ['crumbs' => [$database->book]])</div>
        <main class="content-wrap card">
            <h1 class="list-heading">{{ trans('databases.edit_record') }}</h1>
            <p><a href="{{ url('/databases/' . $database->id) }}">{{ $database->name }}</a></p>
            @if ($canEdit)
            <form action="{{ url('/databases/' . $database->id . '/records/' . $record['id']) }}" method="POST">
                {{ csrf_field() }}
                <input type="hidden" name="_method" value="PUT">
                <input type="hidden" name="version" value="{{ $record['version'] }}">
                <input type="hidden" name="page_revision" value="{{ $record['page_revision'] }}">
                <div class="form-group">
                    <label for="record-name">{{ trans('databases.record_name') }}</label>
                    <input class="form-control" id="record-name" name="name" type="text" value="{{ old('name', $record['name']) }}" maxlength="255" required>
                    @include('form.errors', ['name' => 'name'])
                </div>
                @include('databases.parts.field-inputs', ['fields' => $fields, 'values' => $record['values'], 'targets' => $targets, 'relatedFields' => []])
                <div class="text-right">
                    <a class="button outline" href="{{ url('/databases/' . $database->id) }}">{{ trans('common.cancel') }}</a>
                    <button type="submit" class="button">{{ trans('common.save') }}</button>
                </div>
            </form>
            @else
                <p>{{ trans('databases.record_edit_denied') }}</p>
            @endif
        </main>
    </div>
@stop
