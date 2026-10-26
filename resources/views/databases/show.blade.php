@extends('layouts.simple')

@section('title', $database->name)

@section('body')
    <div class="container">
        <div class="flex-container-row items-center justify-space-between mb-m">
            <div>@include('entities.breadcrumbs', ['crumbs' => [$database->book]])</div>
            <a href="{{ url('/databases') }}">{{ trans('databases.all_databases') }}</a>
        </div>
        <main class="content-wrap">
            <header class="flex-container-row wrap items-center justify-space-between mb-m">
                <div class="flex">
                    <p class="text-muted mb-none">{{ $database->book->name }}</p>
                    <h1 class="list-heading">{{ $database->name }}</h1>
                </div>
                <a class="button outline" href="{{ url('/databases/' . $database->id . '/export') }}">{{ trans('databases.export') }}</a>
            </header>

            @if ($canCreate)
                <details class="card p-m mb-m" aria-labelledby="record-add-heading" @if ($errors->has('values') || collect($errors->keys())->contains(fn($key) => str_starts_with($key, 'values.')) || ($errors->has('name') && !$errors->has('type') && !old('type') && !$errors->has('config.target_database_id') && !$errors->has('version'))) open @endif>
                    <summary id="record-add-heading" class="bold">{{ trans('databases.add_record') }}</summary>
                    <form action="{{ url('/databases/' . $database->id . '/records') }}" method="POST">
                        {{ csrf_field() }}
                        <div class="form-group">
                            <label for="record-name">{{ trans('databases.record_name') }}</label>
                            <input class="form-control" id="record-name" name="name" type="text" value="{{ old('name') }}" maxlength="255" required>
                            @include('form.errors', ['name' => 'name'])
                        </div>
                        @include('databases.parts.field-inputs', ['fields' => $fields, 'values' => [], 'targets' => $targets, 'relatedFields' => $relatedFields ?? []])
                        <div class="text-right"><button type="submit" class="button">{{ trans('databases.add_record') }}</button></div>
                    </form>
                </details>
            @endif

            @if ($canManage)
                <details class="card p-m mb-m" @if ($errors->has('type') || $errors->has('config.options') || $errors->has('config.target_database_id') || $errors->has('config.relation_field_id') || $errors->has('config.target_field_id') || $errors->has('config.operation') || ($errors->has('name') && old('type'))) open @endif>
                    <summary class="bold">{{ trans('databases.add_field') }}</summary>
                    <form action="{{ url('/databases/' . $database->id . '/fields') }}" method="POST" class="mt-m">
                        {{ csrf_field() }}
                        <input type="hidden" name="version" value="{{ $database->version }}">
                        <div class="form-group">
                            <label for="field-name">{{ trans('databases.field_name') }}</label>
                            <input class="form-control" id="field-name" name="name" value="{{ old('name') }}" maxlength="255" required>
                            @include('form.errors', ['name' => 'name'])
                        </div>
                        <div class="form-group">
                            <label for="field-type">{{ trans('databases.field_type') }}</label>
                            <select class="form-control" id="field-type" name="type" required>
                                @foreach (['text', 'number', 'checkbox', 'date', 'select', 'multi_select', 'url', 'email', 'relation', 'rollup'] as $type)
                                    <option value="{{ $type }}" @if(old('type') === $type) selected @endif>{{ trans('databases.types.' . $type) }}</option>
                                @endforeach
                            </select>
                            @include('form.errors', ['name' => 'type'])
                        </div>
                        <div class="form-group">
                            <label for="field-options">{{ trans('databases.options') }}</label>
                            <input class="form-control" id="field-options" name="config[options]" value="{{ old('config.options') }}" aria-describedby="field-options-help">
                            <p id="field-options-help" class="text-muted small">{{ trans('databases.options_help') }}</p>
                            @include('form.errors', ['name' => 'config.options'])
                        </div>
                        <div class="form-group">
                            <label for="relation-database">{{ trans('databases.related_database') }}</label>
                            <select class="form-control" id="relation-database" name="config[target_database_id]">
                                <option value="">{{ trans('databases.choose_database') }}</option>
                                @foreach ($databases as $targetDatabase)
                                    <option value="{{ $targetDatabase->id }}" @if(old('config.target_database_id') == $targetDatabase->id) selected @endif>{{ $targetDatabase->name }}</option>
                                @endforeach
                            </select>
                            <p class="text-muted small">{{ trans('databases.related_database_help') }}</p>
                            @include('form.errors', ['name' => 'config.target_database_id'])
                        </div>
                        <div class="form-group">
                            <label for="rollup-relation">{{ trans('databases.rollup_relation') }}</label>
                            <select class="form-control" id="rollup-relation" name="config[relation_field_id]">
                                <option value="">{{ trans('databases.choose_relation') }}</option>
                                @foreach ($fields as $field)
                                    @if ($field->type === 'relation')
                                        <option value="{{ $field->id }}" @if(old('config.relation_field_id') == $field->id) selected @endif>{{ $field->name }}</option>
                                    @endif
                                @endforeach
                            </select>
                            @include('form.errors', ['name' => 'config.relation_field_id'])
                        </div>
                        <div class="form-group">
                            <label for="rollup-target">{{ trans('databases.rollup_target') }}</label>
                            <select class="form-control" id="rollup-target" name="config[target_field_id]">
                                <option value="">{{ trans('databases.choose_target_field') }}</option>
                                @foreach ($databases as $targetDatabase)
                                    @php($targetFieldSet = $relatedFields[$targetDatabase->id] ?? [])
                                    @php($numericFields = $targetFieldSet->where('type', 'number'))
                                    @if ($numericFields->isNotEmpty())
                                        <optgroup label="{{ $targetDatabase->name }}">
                                            @foreach ($numericFields as $targetField)
                                                <option value="{{ $targetField->id }}" @if(old('config.target_field_id') == $targetField->id) selected @endif>{{ $targetField->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                @endforeach
                            </select>
                            <p class="text-muted small">{{ trans('databases.rollup_target_help') }}</p>
                            @include('form.errors', ['name' => 'config.target_field_id'])
                        </div>
                        <div class="form-group">
                            <label for="rollup-operation">{{ trans('databases.rollup_operation') }}</label>
                            <select class="form-control" id="rollup-operation" name="config[operation]">
                                @foreach (['count', 'sum', 'average', 'min', 'max'] as $operation)
                                    <option value="{{ $operation }}" @if(old('config.operation') === $operation) selected @endif>{{ trans('databases.operations.' . $operation) }}</option>
                                @endforeach
                            </select>
                            @include('form.errors', ['name' => 'config.operation'])
                        </div>
                        <div class="text-right"><button type="submit" class="button">{{ trans('databases.add_field') }}</button></div>
                    </form>
                </details>
            @endif

            <form action="{{ url('/databases/' . $database->id) }}" method="GET" class="flex-container-row items-center mb-s">
                <label class="screen-reader-only" for="database-search">{{ trans('databases.search') }}</label>
                <input class="form-control" id="database-search" name="search" type="search" value="{{ $search }}" placeholder="{{ trans('databases.search') }}">
                <button class="button outline" type="submit">{{ trans('common.search') }}</button>
            </form>

            @if (count($records) === 0)
                <div class="card p-m text-center">
                    <h2>{{ trans('databases.no_records_title') }}</h2>
                    <p>{{ trans('databases.no_records_description') }}</p>
                </div>
            @else
                <div style="overflow-x: auto;" tabindex="0" role="region" aria-label="{{ trans('databases.records_caption', ['name' => $database->name]) }}">
                    <table class="table" style="min-width: {{ max(480, ($fields->count() + 1) * 140) }}px;">
                        <caption class="text-muted">{{ trans('databases.records_caption', ['name' => $database->name]) }}</caption>
                        <thead><tr><th scope="col">{{ trans('databases.record_name') }}</th>
                            @foreach ($fields as $field)<th scope="col">{{ $field->name }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <th scope="row"><a href="{{ $record['url'] }}">{{ $record['name'] }}</a>
                                    @if ($record['can_edit'] ?? false)<div class="small"><a href="{{ url('/databases/' . $database->id . '/records/' . $record['id'] . '/edit') }}">{{ trans('databases.edit_record') }}</a></div>@endif
                                </th>
                                @foreach ($fields as $field)
                                    @php($value = $record['values'][$field->id] ?? null)
                                    <td>
                                        @if ($field->type === 'checkbox')
                                            {{ $value ? trans('databases.yes') : trans('databases.no') }}
                                        @elseif ($field->type === 'relation')
                                            @foreach ((array) $value as $relatedRecord)
                                                @if (is_array($relatedRecord) && !empty($relatedRecord['url']) && !empty($relatedRecord['name']))
                                                    <a href="{{ $relatedRecord['url'] }}">{{ $relatedRecord['name'] }}</a>@if (!$loop->last), @endif
                                                @endif
                                            @endforeach
                                        @elseif ($field->type === 'multi_select' && is_array($value))
                                            {{ implode(', ', $value) }}
                                        @elseif ($field->type === 'url' && $value)
                                            <a href="{{ $value }}" rel="nofollow noopener">{{ $value }}</a>
                                        @elseif ($value !== null && $value !== '')
                                            {{ is_array($value) ? implode(', ', $value) : $value }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </main>
    </div>
@stop
