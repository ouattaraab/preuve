@extends('admin.layout')

@section('contenu')
    {{--
        Registre des biens. Colonnes reprises de la maquette : IDENTIFIANT,
        BIEN, STATUT, FIABILITÉ, ENREGISTRÉ, CONSULT. 30 J.

        Aucune colonne « détenteur » : l'anonymat est symétrique et ne connaît
        pas d'exception interne (règle métier absolue n° 4).
    --}}
    <form id="filtres-registre" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px" onsubmit="return false">
        <input id="q" placeholder="Identifiant ou référence publique" autocomplete="off"
               style="flex:1;min-width:240px;padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
        <select id="statut" style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
            <option value="">Tous les statuts</option>
            @foreach (\App\Enums\LifeStatus::cases() as $statut)
                <option value="{{ $statut->value }}">{{ $statut->label() }}</option>
            @endforeach
        </select>
        <select id="fiabilite" style="padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
            <option value="">Toutes fiabilités</option>
            @foreach (\App\Enums\TrustLevel::cases() as $niveau)
                <option value="{{ $niveau->value }}">{{ $niveau->label() }}</option>
            @endforeach
        </select>
    </form>

    <div id="table-registre"><p style="color:#7A6A55;font-size:14px">Chargement…</p></div>
    <div id="pagination-registre" style="display:flex;align-items:center;gap:12px;margin-top:16px"></div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        🔒 Ce registre ne dit rien des détenteurs. L'anonymat vaut aussi à
        l'intérieur : un back-office qui le lèverait deviendrait un outil de
        traque pour quiconque obtient un compte agent.
    </p>
@endsection
