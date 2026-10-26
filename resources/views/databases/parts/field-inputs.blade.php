@foreach ($fields as $field)
    @php
        $fieldId = $field->id;
        $fieldName = 'values[' . $fieldId . ']';
        $fieldValue = old('values.' . $fieldId, $values[$fieldId] ?? null);
        $fieldErrorName = 'values.' . $fieldId;
        $fieldOptions = (array) data_get($field, 'config.options', []);
    @endphp
    <div class="form-group">
        <label for="field-{{ $fieldId }}">{{ $field->name }}</label>
        @if ($field->type === 'rollup')
            <p class="text-muted" id="field-{{ $fieldId }}">{{ trans('databases.rollup_read_only') }}</p>
        @elseif ($field->type === 'checkbox')
            <input type="hidden" name="{{ $fieldName }}" value="0">
            <label class="custom-checkbox">
                <input id="field-{{ $fieldId }}" type="checkbox" name="{{ $fieldName }}" value="1" @if($fieldValue) checked @endif>
                <span>{{ trans('databases.checked') }}</span>
            </label>
        @elseif ($field->type === 'select')
            <select class="form-control" id="field-{{ $fieldId }}" name="{{ $fieldName }}">
                <option value="">{{ trans('databases.no_value') }}</option>
                @foreach ($fieldOptions as $option)
                    <option value="{{ $option }}" @if((string) $fieldValue === (string) $option) selected @endif>{{ $option }}</option>
                @endforeach
            </select>
        @elseif ($field->type === 'multi_select')
            @php($selectedOptions = is_array($fieldValue) ? $fieldValue : (array) $fieldValue)
            <input type="hidden" name="{{ $fieldName }}" value="">
            <select class="form-control" id="field-{{ $fieldId }}" name="{{ $fieldName }}[]" multiple size="{{ min(max(count($fieldOptions), 3), 8) }}" style="height: auto; min-height: 6rem;">
                @foreach ($fieldOptions as $option)
                    <option value="{{ $option }}" @if(in_array($option, $selectedOptions)) selected @endif>{{ $option }}</option>
                @endforeach
            </select>
        @elseif ($field->type === 'relation')
            @php($selectedIds = collect(is_array($fieldValue) ? $fieldValue : (array) $fieldValue)->map(fn($item) => is_array($item) ? ($item['id'] ?? null) : (is_object($item) ? ($item->id ?? null) : $item))->filter()->all())
            <input type="hidden" name="{{ $fieldName }}" value="">
            <select class="form-control" id="field-{{ $fieldId }}" name="{{ $fieldName }}[]" multiple size="{{ min(max(count($targets[$fieldId] ?? []), 3), 8) }}" style="height: auto; min-height: 6rem;">
                @foreach ($targets[$fieldId] ?? [] as $target)
                    <option value="{{ $target['id'] }}" @if(in_array($target['id'], $selectedIds)) selected @endif>{{ $target['name'] }}</option>
                @endforeach
            </select>
        @else
            @php($inputType = ['number' => 'number', 'date' => 'date', 'url' => 'url', 'email' => 'email'][$field->type] ?? 'text')
            <input class="form-control" id="field-{{ $fieldId }}" type="{{ $inputType }}" name="{{ $fieldName }}" value="{{ $fieldValue }}" @if($field->type === 'number') step="any" @endif>
            @if ($field->type === 'number')<p class="text-muted small">{{ trans('databases.number_help') }}</p>@endif
        @endif
        @include('form.errors', ['name' => $fieldErrorName])
    </div>
@endforeach
