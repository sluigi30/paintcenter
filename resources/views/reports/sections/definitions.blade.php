<x-rpt.section :section="$section">
    <dl class="rpt-definitions">
        @foreach($report['definitions'] as $definition)
            <dt>{{ $definition['term'] }}</dt>
            <dd>{{ $definition['definition'] }}</dd>
        @endforeach
    </dl>
</x-rpt.section>
