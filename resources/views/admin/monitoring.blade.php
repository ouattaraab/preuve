@extends('admin.layout')

@section('contenu')
    {{--
        Supervision. Les trois contrôles de la sonde sont mis en avant parce
        qu'ils portent des défauts SILENCIEUX : rien ne casse, les utilisateurs
        ne voient rien, et la plateforme cesse pourtant de tenir sa promesse.
    --}}
    <div id="sonde" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:26px">
        <p style="color:#7A6A55;font-size:14px">Chargement…</p>
    </div>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:18px;margin-bottom:14px">Promesses produit</h2>
    <div id="telemetrie" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-bottom:26px">
        <p style="color:#7A6A55;font-size:14px">Chargement…</p>
    </div>

    <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:18px;margin-bottom:14px">Signaux anti-fraude</h2>
    <div id="fraude" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
        <p style="color:#7A6A55;font-size:14px">Chargement…</p>
    </div>

    <p style="margin-top:24px;padding:14px 16px;background:#FFF6E8;border-radius:10px;font-size:13px;color:#5C4A33;line-height:1.6">
        Aucun signal n'est une accusation. Un acheteur de bonne foi qui enregistre
        le bien qu'il vient d'acquérir déclenche exactement le même signal qu'un
        fraudeur : ce tableau prépare une décision humaine, il n'en prend aucune.
    </p>

    @if ($estAdministrateur)
        {{--
            Deux adresses, deux rôles, et aucune des deux ne doit rester vide.

            Sans destinataire d'exploitation, les rapports d'anomalie partent
            nulle part et la surveillance n'existe que sur le papier. Sans point
            de contact publié, la page de confidentialité annonce un guichet en
            cours d'ouverture — ce qui est vrai, mais ne peut pas le rester.
        --}}
        <section style="margin-top:34px;border:2px solid #E4DBC8;border-radius:12px;padding:20px 22px;background:#FFF6E8">
            <h2 style="font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:18px">
                Adresses d'exploitation
            </h2>

            <div style="margin-top:16px">
                <label for="ad-ops" style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">
                    Destinataire des rapports d'exploitation
                </label>
                <p id="etat-ops" style="font-size:12px;color:#7A6A55;margin-bottom:8px">Chargement…</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <input id="ad-ops" type="email" placeholder="exploitation@exemple.ci" autocomplete="off"
                           style="flex:1;min-width:260px;padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
                    <button id="ad-ops-appliquer"
                            style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer">
                        Enregistrer
                    </button>
                </div>
            </div>

            <div style="margin-top:22px">
                <label for="ad-legal" style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">
                    Contact pour l'exercice des droits (publié sur la page de confidentialité)
                </label>
                <p id="etat-legal" style="font-size:12px;color:#7A6A55;margin-bottom:8px">Chargement…</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <input id="ad-legal" type="email" placeholder="donnees@exemple.ci" autocomplete="off"
                           style="flex:1;min-width:260px;padding:10px 14px;border:2px solid #E4DBC8;border-radius:10px;font-size:14px;background:#fff;color:#2B1D12">
                    <button id="ad-legal-appliquer"
                            style="background:#2B1D12;color:#FFF6E8;border:none;border-radius:10px;padding:10px 18px;font-size:14px;font-weight:700;cursor:pointer">
                        Publier
                    </button>
                </div>
            </div>

            <p style="margin-top:16px;font-size:13px;color:#5C4A33;line-height:1.6">
                ⚠️ Cette adresse est publique et doit <strong>recevoir et traiter</strong> des demandes
                d'accès, de rectification et d'effacement. En publier une qui ne répond pas ferait
                passer le silence pour un refus.
            </p>
        </section>
    @endif
@endsection
