/* global TSOIMMA, jQuery */
(function($) {
    'use strict';

    // All i18n strings come from TSOIMMA.strings (passed by wp_localize_script in PHP).
    // This replaces the previous JSON.parse approach to comply with WP.org guidelines.
    var L = TSOIMMA.strings || {};
    var BASE_L = $.extend({}, L);
    var CA_ORIGINAL = {
        text: {},
        placeholder: {},
        html: {}
    };

    // ================================================================
    // State
    // ================================================================
    var PER_PAGE_ALLOWED = [21, 35, 49, 70, 105];
    var PER_PAGE_STORAGE_KEY = 'tsoimma_images_per_page';

    function readStoredPerPage() {
        try {
            var stored = parseInt(window.localStorage.getItem(PER_PAGE_STORAGE_KEY), 10);
            if (PER_PAGE_ALLOWED.indexOf(stored) !== -1) {
                return stored;
            }
        } catch (e) {}
        return 35;
    }

    function writeStoredPerPage(value) {
        try {
            window.localStorage.setItem(PER_PAGE_STORAGE_KEY, String(value));
        } catch (e) {}
    }

    function setSelectValue($select, value) {
        if (!$select.length) return;
        $select.val(String(value));
        var api = $select.closest('.imp-csel').data('impCselApi');
        if (api && typeof api.sync === 'function') {
            api.sync();
        } else {
            var $label = $select.closest('.imp-csel').find('.imp-csel-label');
            var text = $select.find('option:selected').first().text();
            if ($label.length && text) {
                $label.text(text);
            }
        }
    }

    function applyPerPage(value, reload) {
        var n = parseInt(value, 10);
        if (PER_PAGE_ALLOWED.indexOf(n) === -1) {
            n = 35;
        }
        state.opt.perPage = n;
        state.seo.perPage = n;
        writeStoredPerPage(n);
        setSelectValue($('#imp-opt-per-page'), n);
        setSelectValue($('#imp-seo-per-page'), n);
        if (reload) {
            state.opt.page = 1;
            state.seo.page = 1;
            state.opt.selected.clear();
            updateSelectedCount();
            loadOptImages();
            loadSeoImages();
        }
    }

    var state = {
        opt: {
            page: 1, perPage: readStoredPerPage(), search: '', totalPages: 1,
            selected: new Set()
        },
        seo: {
            page: 1, perPage: readStoredPerPage(), search: '', totalPages: 1
        },
        orphans: { found: [] },
        orphanSelected: new Set(),
        dashboard: { altPage: 1, altSelected: new Set(), altDraftById: {}, engines: {}, overviewLoaded: false, altListLoaded: false, altListCacheKey: '', dirty: false, xhrOverview: null, xhrAlt: null },
        currentModalId: null,
        imgCacheTs: {}
    };
    var refreshUrlFixerUi = null;
    var hoverPreviewLoadId = 0;

    function hideImgHoverPreview() {
        hoverPreviewLoadId += 1;
        var $preview = $('#imp-img-hover-preview');
        if (!$preview.length) {
            return;
        }
        $preview.removeClass('visible').hide();
        $preview.find('img').attr('src', '');
    }

    // ================================================================
    // Init
    // ================================================================
    $(function() {
        initThemeSwitcher();
        initLanguageSwitcher();
        initImpCustomSelects();
        applyPerPage(readStoredPerPage(), false);
        initTabs();
        initDashboard();
        initHelpTips();
        initEngineInfo();
        initQualitySliders();
        initSearch();
        initOptimizeTab();
        initOrphansTab();
        initSizesTab();
        initSeoTab();
        initModal();
        initPdfTab();
        initAutoTab();
        initHistoryTab();
        initUrlFixer();
        initHoverPreview();
        checkWebP();
        loadOptImages();
        loadSeoImages();
        loadAutoSettings();
        var deepId = parseInt(new URLSearchParams(window.location.search).get('tsoimma_id') || '0', 10);
        if (deepId > 0) {
            $('.imp-tab[data-tab="optimize"]').trigger('click');
            setTimeout(function() { openModal(deepId, 'optimize'); }, 400);
        }
    });

    // ================================================================
    // Language switcher (CA / ES / EN)
    // ================================================================
    var I18N_STATIC = {
        ca: {
            scan_rogue: '🔍 Escanejar fitxers extra',
            rogue_title: '🗂 Fitxers extra a uploads',
            rogue_desc: 'Escaneja fitxers a <code>uploads/</code> que WordPress no té registrats: <strong>còpies de seguretat TSO</strong> (<code>_tso_im_backup</code>), temporals (<code>_tso_im_opt</code>), doble extensió (<code>.jpg.webp</code>), backups antics (<code>.bk</code>), etc. Revisa abans d\'eliminar les còpies de seguretat.',
            rename_btn: '✏️ Reanomenar fitxer',
            repair_paths: '🔧 Reparar imatges amb path trencat',
            mime_fix_btn: '🔧 Reparar mime types incorrectes',
            scan_ghosts: 'Escanejar adjunts fantasma',
            delete_ghosts: 'Eliminar seleccionats',
            ghost_found_singular: 'adjunt fantasma trobat.',
            ghost_found_plural: 'adjunts fantasma trobats.',
            selected_of: 'seleccionats de',
            fixable_label: 'reparables',
            appears_in: 'Apareix a',
            occurrence: 'ocurrència',
            occurrences: 'ocurrències',
            url_items_suffix: 'URL(s)?',
            skipped_suffix: 'omeses',
            compressed_tag: 'Comprimit',
            pdf_preview_btn: '👁 Veure',
            pdf_preview_title: 'Previsualització PDF',
            pdf_open_tab: 'Obrir en pestanya nova',
            pdf_preview_fallback: 'Si la previsualització no es carrega al navegador, obre el PDF en una pestanya nova.',
            non_compressible_tag: 'No comprimible',
            webp_label: 'WebP',
            file_unit_suffix: 'fitxer(s)?',
            hdr_file: 'Fitxer',
            hdr_size: 'Mida',
            hdr_savings: 'Estalvi',
            hdr_date: 'Data',
            hdr_image: 'Imatge',
            hdr_action: 'Acció',
            hdr_details: 'Detalls',
            hdr_user: 'Usuari',
            system_user: 'Sistema',
            action_optimize: 'Optimitzada',
            action_auto_optimize: 'Auto-optimitzada',
            action_rename: 'Reanomenada',
            action_seo_update: 'SEO actualitzat',
            action_delete: 'Eliminada',
            action_pdf_compress: 'PDF comprimit',
            action_revert: 'Revertida',
            action_dup_merge_rewrite: 'Fusió: reapuntada',
            action_dup_merge_delete: 'Fusió: eliminada',
            action_dup_delete_unused: 'Duplicat eliminat',
            action_dup_detach: 'Desvinculada',
            hist_merged_into: 'Fusionada amb',
            hist_replaced_by: 'Substituïda per',
            hist_no_refs_found: 'Sense referències trobades',
            hist_detached_from: 'Desvinculada de',
            donate_support: '☕ Dona suport al plugin',
            theme_day: 'Mode dia',
            theme_night: 'Mode nit',
            theme_auto: 'Mode auto',
            theme_auto_hint: 'Segons alba i ocàs (canvia amb l\'estació)',
            auto_source_formats_label: 'Formats d\'origen per auto-convertir',
            auto_src_jpg: 'JPG/JPEG',
            auto_src_png: 'PNG',
            auto_src_webp: 'WEBP',
            auto_src_gif: 'GIF (només estàtic)',
            auto_src_bmp: 'BMP',
            auto_src_tiff: 'TIFF',
            modal_seo: 'SEO',
            preset_fhd: 'Full HD',
            preset_hd: 'HD',
            preset_web: 'Web',
            preset_medium: 'Mitjà',
            preset_small: 'Petit',
            rogue_reason_double_extension: 'Doble extensió (.jpg.webp)',
            rogue_reason_tso_backup: 'Backup TSO',
            rogue_reason_tso_temp: 'Temporal TSO',
            rogue_reason_tso_pdf_compressed: 'PDF comprimit TSO',
            rogue_reason_generic_backup: 'Backup genèric (.bk)',
            rogue_reason_unregistered_wp_db: 'Fitxer no registrat a la BD de WordPress',
            rogue_delete_allowlist_note: 'Pots eliminar fitxers escanejats durant 24 h (només l\'admin que ha escanejat). Torna a escanejar si l\'eliminació falla.',
            rogue_rescan_required: 'Alguns fitxers s\'han omès: torna a escanejar abans d\'eliminar (la llista caduca al cap de 24 h).',
            auto_clean_title: '🕐 Neteja automàtica de l\'historial',
            retention_days_label: 'Conservar:',
            retention_days_unit: 'dies',
            purge_interval_label: 'Comprovació:',
            interval_daily: 'Cada dia',
            interval_weekly: 'Cada setmana',
            interval_monthly: 'Cada mes',
            retention_zero_hint: '0 dies = desactivat',
            retention_saved: 'Desat!',
            retention_invalid: 'Valor invàlid. Usa 0 per desactivar, o entre 1 i 3650 dies.',
            tab_dashboard: 'Resum',
            tab_optimize: 'Optimitzar',
            tab_orphans: 'Imatges òrfenes',
            tab_seo: 'SEO i noms',
            tab_pdf: 'PDFs',
            tab_auto: 'Auto-optimització',
            tab_history: 'Historial',
            tab_urls: 'URLs',
            opt_config_title: 'Configuració d\'Optimització',
            format_label: 'Format de sortida',
            quality_label: 'Qualitat',
            search_image_ph: '🔎 Cercar imatge...',
            select_all: 'Seleccionar tot',
            deselect: 'Deseleccionar',
            bulk_optimize: 'Optimitzar seleccionades',
            bulk_replace_label: 'Reemplaça l\'original i actualitza tots els links',
            orphans_title: 'Trobar Imatges Òrfenes',
            orphans_desc: 'Imatges de la biblioteca que <strong>no estan referenciades</strong> a cap article, pàgina, widget ni metadada.',
            orphan_limit_label: 'Imatges a escanejar per lot',
            all_slow: 'Totes (lent)',
            scan_now: '🔍 Escanejar ara',
            delete_selected: 'Eliminar seleccionades',
            sort_size: '📦 Ordenar per pes (major primer)',
            sort_date: '📅 Ordenar per data de creació',
            sort_modified: '✏️ Ordenar per data de modificació',
            modal_rename: 'Reanomenar',
            modal_optimize: 'Optimitzar',
            seo_title_label: 'Títol',
            seo_alt_label: 'Text alternatiu (Alt)',
            seo_caption_label: 'Peu de foto (Caption)',
            seo_desc_label: 'Descripció',
            seo_title_ph: 'Títol descriptiu de la imatge',
            seo_alt_ph: 'Descripció per a accessibilitat i SEO',
            seo_caption_ph: 'Text visible sota la imatge',
            seo_desc_ph: 'Descripció llarga...',
            current_filename: 'Nom actual del fitxer',
            new_filename: 'Nou nom (sense extensió)',
            filename_ph: 'p.ex: pastis-de-xocolata-recepta',
            rename_hint: 'Es manté el text UTF-8 (p. ex. català) i només es netegen caràcters invàlids.',
            auto_suggest: 'Suggeriment automàtic',
            use_btn: 'Usar',
            resize_title: 'Redimensionar imatge',
            resize_desc: 'Opcional — redueix les mides en píxels',
            max_width: 'Ample màx',
            max_height: 'Alt màx',
            proportions_hint: 'Proporcions preservades. Deixa un camp buit per calcular-lo automàticament.',
            replace_title: 'Reemplaçar l\'original',
            replace_desc: 'Actualitza automàticament tots els links a la web',
            optimize_now: '⚡ Optimitzar ara',
            pdf_title: 'Comprimir PDFs',
            pdf_desc: 'Redueix el pes dels PDFs de la biblioteca sense canviar la URL ni trencar enllaços.<br><strong>Requereix:</strong> GhostScript al servidor (recomanat) o extensió Imagick de PHP.',
            pdf_quality_label: 'Qualitat / DPI',
            dpi_72: '72 DPI — Molt lleuger (pantalla)',
            dpi_96: '96 DPI — Recomanat (web)',
            dpi_150: '150 DPI — Alta qualitat',
            dpi_300: '300 DPI — Impressió',
            pdf_replace_label: 'Reemplaça l\'original (la URL no canvia)',
            search_pdf_ph: '🔎 Cercar PDF...',
            compress_selected: 'Comprimir seleccionats',
            auto_title: 'Auto-optimització en pujar imatges',
            auto_desc: 'En activar aquesta opció, <strong>cada imatge nova que puges</strong> s\'optimitza automàticament amb la configuració triada.',
            save_config: '💾 Guardar configuració',
            mime_fix_title: 'Reparació de tipus mime',
            mime_fix_desc: 'Detecta i repara adjunts amb extensió .webp però mime type incorrecte (image/jpeg). Soluciona imatges invisibles a la Mediateca.',
            auto_stats_title: 'Estadístiques d\'auto-optimització',
            auto_history_title: 'Historial d\'auto-optimització',
            auto_history_hint: 'Les auto-optimitzacions es registren a la pestanya <strong>Historial</strong> (filtre 🤖 Auto-optimitzades).',
            history_title: 'Historial de canvis',
            history_filter_label: 'Filtrar per acció',
            all_actions: 'Totes les accions',
            filter_optimize: '⚡ Optimitzades',
            filter_auto: '🤖 Auto-optimitzades',
            filter_rename: '✏️ Reanomenades',
            filter_seo: '🏷️ SEO actualitzat',
            filter_delete: '🗑️ Eliminades',
            filter_pdf: '📄 PDFs comprimits',
            date_from: 'Des de',
            date_to: 'Fins a',
            search_file: 'Cercar fitxer',
            search_file_ph: 'nom del fitxer...',
            history_load: '🔄 Carregar / filtrar',
            clear_30: '🗑️ Netejar >30 dies',
            clear_all: '🗑️ Netejar tot',
            save_btn: '💾 Guardar',
            url_title: '🔗 Detector d\'URLs inconsistents',
            url_desc: 'Detecta URLs d\'imatges a entrades i pàgines que <strong>apunten a fitxers que ja no existeixen</strong> al servidor — normalment per conversions de JPG a WebP. Pot actualitzar-les automàticament a tota la base de dades.',
            url_obsolete: 'URL obsoleta → nova correcta',
            scan_web: '🔍 Escanejar tot el lloc',
            select_fixable: 'Seleccionar reparables',
            fix_selected: '✅ Reparar seleccionades',
            files_label: 'fitxers',
            remaining_label: 'restants',
            open_title: 'Obrir',
            error_prefix: 'Error: ',
            ghost_confirm_suffix: 'adjunt(s) fantasma? Això elimina els registres de la base de dades.',
            post_label: 'Entrada',
            page_label: 'Pàgina',
            dash_title: 'Resum del lloc',
            dash_desc: 'Visió ràpida de la salut de les imatges, motors disponibles i accions pendents.',
            dash_total_images: 'Imatges a la biblioteca',
            dash_missing_alt: 'Alt text absent',
            dash_backups: 'Còpies de seguretat TSO',
            dash_saved: 'Espai estalviat',
            dash_operations: 'operacions del plugin',
            dash_auto_on: 'Auto-optimitzar ACTIVAT',
            dash_auto_off: 'Auto-optimitzar DESACTIVAT',
            dash_auto_label: 'Auto-optimitzar',
            dash_auto_status_on: 'ACTIVAT',
            dash_auto_status_off: 'DESACTIVAT',
            dash_auto_format_sub: 'Format de sortida: %s',
            dash_auto_disabled_hint: 'Desactivat — activa a la pestanya Auto',
            dash_alt_title: 'Imatges sense alt (o alt genèric)',
            dash_alt_desc: 'Suggereix alt des del títol o nom de fitxer. Clica ✎ per editar una fila; Desar (verd) guarda l\'alt. «Omplir alt seleccionades» usa el text editat.',
            dash_alt_save: 'Desar',
            dash_alt_edit: 'Editar alt',
            dash_alt_accept: 'Acceptar alt suggerit',
            dash_alt_cancel: 'Cancel·lar',
            dash_alt_used_only: 'Només usades en contingut',
            dash_alt_used_only_tip: 'Mostra només imatges referenciades en entrades, pàgines, imatges destacades, widgets o camps personalitzats. Oculta pujades no usades de la biblioteca.',
            dash_alt_fill: 'Omplir alt seleccionades',
            dash_alt_fill_tip: 'Escriu l\'alt suggerit (títol si és útil, sinó nom de fitxer humanitzat) a cada imatge seleccionada. Omet les que ja tenen un alt útil.',
            dash_alt_suggested: 'Alt suggerit',
            dash_alt_file_col: 'Fitxer',
            dash_alt_used_in: 'Usada a',
            dash_alt_updated: 'texts alt actualitzats.',
            dash_alt_skipped: 'omeses (ja tenien alt).',
            dash_alt_manual_hint: 'Escriu text alt…',
            dash_engine_gd: 'GD WebP',
            dash_engine_avif: 'GD AVIF',
            dash_engine_gs: 'GhostScript',
            dash_engine_imagick: 'Imagick',
            dash_engine_info_title: 'Motors del servidor',
            dash_engine_gd_desc: 'PHP GD amb lectura/escriptura WebP. Necessari per convertir a WebP i la majoria d\'optimitzacions.',
            dash_engine_avif_desc: 'PHP GD amb lectura/escriptura AVIF. Només cal si tries AVIF com a format de sortida.',
            dash_engine_gs_desc: 'Eina de línia de comandes per comprimir PDFs a la pestanya PDFs.',
            dash_engine_imagick_desc: 'Extensió PHP ImageMagick. Reserva per comprimir PDFs i algunes tasques d\'imatge.',
            dash_engine_available: 'Disponible en aquest servidor.',
            dash_engine_unavailable: 'No disponible en aquest servidor.',
            dash_engine_why_fail: 'Per què no funciona',
            dash_engine_close: 'Tancar',
            dash_go_orphans: 'Trobar òrfenes',
            dash_go_urls: 'Reparar URLs',
            dash_alt_all_ok: 'Totes les imatges tenen un alt útil.',
            dash_queue_title: 'Cua en segon pla',
            dash_queue_desc: 'Les optimitzacions massives s\'executen en segon pla via WP-Cron (5 imatges per lot).',
            dash_queue_cancel: 'Cancel·lar pendents / miniatures',
            dash_queue_empty: 'La cua és buida.',
            dash_queue_done: 'fetes',
            dash_queue_pending: 'pendents',
            dash_queue_processing: 'processant',
            dash_queue_thumbs: 'miniatures pendents',
            dash_queue_errors: 'errors',
            dash_queue_queued: 'En cua per processar en segon pla...',
            dash_queue_queued_n: 'imatges en cua.',
            dash_queue_skipped_n: 'ometudes (ocupades o ja a la cua).',
            dash_backup_title: 'Retenció de còpies de seguretat',
            dash_backup_desc: 'Elimina automàticament les còpies TSO a uploads/tso-image-master/ (0 = desactivat).',
            dash_backup_days: 'Conservar còpies (dies)',
            dash_backup_max: 'Mida màxima total (MB, 0 = il·limitat)',
            dash_backup_purge: 'Purga ara',
            dash_backup_purge_noop: 'La retenció està desactivada (0 dies i 0 MB). Res a netejar.',
            dash_dup_title: 'Imatges duplicades',
            dash_dup_scan: 'Escanejar duplicats',
            dash_dup_none: 'No s\'han trobat grups de duplicats.',
            dash_dup_groups: 'grups de duplicats',
            dash_dup_wasted: 'Espai malgastat',
            dash_dup_explain: 'Cada grup són fitxers idèntics (mateix MD5) registrats més d\'una vegada a la Mediateca. Conserva una entrada i elimina la resta des de la Mediateca de WordPress.',
            dash_dup_rescan_hint: 'Aquestes dades són de l\'últim escaneig. Si acabes d\'editar un article, torna a prémer «Escanejar duplicats» per veure l\'estat actual.',
            dash_dup_group_label: 'Fitxer idèntic',
            dash_dup_entries: 'entrades',
            dash_dup_wasted_in_group: 'malgastats en aquest grup',
            dash_dup_same_name: 'Mateix nom de fitxer però IDs de Mediateca diferents.',
            dash_dup_media_id: 'ID Mediateca',
            dash_dup_upload_path: 'Ubicació',
            dash_dup_uploaded: 'Pujada',
            dash_dup_dimensions: 'Mida',
            dash_dup_refs: 'Referenciada a',
            dash_dup_refs_none: 'No referenciada al contingut (pot ser orfe)',
            dash_dup_refs_more: 'més',
            dash_dup_open_im: 'Obrir a Image Master',
            dash_dup_open_wp: 'Mediateca WP',
            dash_dup_item_title: 'Títol',
            dash_scanned: 'escanejades',
            dash_dup_keep: 'Conserva aquesta (usada al contingut)',
            dash_dup_attached_only: 'Només adjuntada (no es mostra al contingut)',
            dash_dup_delete_ok: 'Es pot eliminar (duplicat no usat)',
            dash_dup_detach: 'Desvincular',
            dash_dup_detach_confirm: 'Desvincular aquest fitxer de l\'entrada a la qual està adjunt a la Biblioteca de mitjans? Això no esborra el fitxer — podràs eliminar-lo després si resulta que no s\'usa enlloc més.',
            dash_dup_detaching: 'Desvinculant...',
            dash_dup_detached_ok: 'Desvinculada.',
            dash_dup_used_direct: 'Usada al contingut',
            dash_dup_used_indirect: 'Només coincidència de nom (aquest ID no és al codi)',
            dash_dup_active_id: 'ID actiu al post',
            dash_dup_open_post: 'Obrir entrada',
            dash_dup_merge: 'Fusionar duplicats',
            dash_dup_merge_hint: 'Conserva els fitxers marcats a sota (en pots marcar més d\'un), reapunta tot el contingut que feia servir la resta i esborra els fitxers redundants.',
            dash_dup_merge_all_kept: 'Tots els fitxers estan marcats per conservar — no hi ha res per fusionar ni esborrar.',
            dash_dup_merge_none_kept: 'Marca almenys un fitxer per conservar abans de fusionar.',
            dash_dup_keep_this: 'Conservar aquest',
            dash_dup_merge_confirm: 'Això reescriurà totes les entrades que fan servir les altres còpies perquè apuntin al fitxer conservat, i després esborrarà els fitxers redundants. No es pot desfer automàticament. Continuar?',
            dash_dup_merging: 'Fusionant...',
            dash_dup_posts_updated: 'entrades actualitzades',
            dash_dup_files_deleted: 'fitxers redundants esborrats',
            dash_dup_kept_review: 'deixats sense tocar (encara referenciats en algun lloc que l\'eina no pot reescriure amb seguretat — revisa’ls a mà)',
            dash_dup_delete: 'Eliminar',
            dash_dup_delete_confirm: 'Eliminar aquest fitxer definitivament? El plugin tornarà a comprovar just abans d\'esborrar que res el referencia. No es pot desfer.',
            dash_dup_deleting: 'Eliminant...',
            dash_dup_deleted_ok: 'Fitxer eliminat.',
            dash_dup_delete_blocked: 'No eliminat: ha aparegut una referència a aquest fitxer des de l\'últim escaneig. Torna a escanejar per veure l\'ús actual.',
            dash_heavy_btn: 'Imatges més pesades',
            auto_skip_kb_label: 'Ometre auto-optimitzar si WebP/AVIF ≤ (KB, 0 = off)',
            auto_fill_alt_label: 'Omplir alt absent en pujar',
            fmt_avif: 'AVIF',
            fmt_png: 'PNG',
            fmt_webp: 'WebP (recomanat)',
            fmt_keep: 'Mantenir format original',
            fmt_keep_current: 'Mantenir format actual',
            per_page_21: '📄 21 / pàgina',
            per_page_35: '📄 35 / pàgina',
            per_page_49: '📄 49 / pàgina',
            per_page_70: '📄 70 / pàgina',
            per_page_105: '📄 105 / pàgina',
            hist_seo_bulk: 'Omplerta en bloc',
            hist_seo_auto: 'En pujar'
        },
        es: {
            tab_optimize: 'Optimizar',
            tab_orphans: 'Imágenes Huérfanas',
            tab_seo: 'SEO y Nombres',
            tab_pdf: 'PDFs',
            tab_auto: 'Auto-optimización',
            tab_history: 'Historial',
            tab_urls: 'URLs',
            opt_config_title: 'Configuración de Optimización',
            format_label: 'Formato de salida',
            fmt_webp: 'WebP (recomendado)',
            fmt_keep: 'Mantener formato original',
            fmt_keep_current: 'Mantener formato actual',
            quality_label: 'Calidad',
            search_image_ph: '🔎 Buscar imagen...',
            select_all: 'Seleccionar todo',
            deselect: 'Deseleccionar',
            bulk_optimize: 'Optimizar seleccionadas',
            bulk_replace_label: 'Reemplaza el original y actualiza todos los enlaces',
            orphans_title: 'Buscar Imágenes Huérfanas',
            orphans_desc: 'Imágenes de la biblioteca que <strong>no están referenciadas</strong> en ninguna entrada, página, widget ni metadato.',
            orphan_limit_label: 'Imágenes a escanear por lote',
            all_slow: 'Todas (lento)',
            scan_now: '🔍 Escanear ahora',
            delete_selected: 'Eliminar seleccionadas',
            rogue_title: '🗂 Archivos extra en uploads',
            rogue_desc: 'Escanea archivos en <code>uploads/</code> que WordPress no tiene registrados: <strong>copias de seguridad TSO</strong> (<code>_tso_im_backup</code>), temporales (<code>_tso_im_opt</code>), doble extensión (<code>.jpg.webp</code>), copias antiguas (<code>.bk</code>), etc. Revisa antes de eliminar las copias de seguridad.',
            sort_size: '📦 Ordenar por peso (mayor primero)',
            sort_date: '📅 Ordenar por fecha de creación',
            sort_modified: '✏️ Ordenar por fecha de modificación',
            modal_rename: 'Renombrar',
            modal_optimize: 'Optimizar',
            seo_title_label: 'Título',
            seo_alt_label: 'Texto alternativo (Alt)',
            seo_caption_label: 'Pie de foto (Caption)',
            seo_desc_label: 'Descripción',
            seo_title_ph: 'Título descriptivo de la imagen',
            seo_alt_ph: 'Descripción para accesibilidad y SEO',
            seo_caption_ph: 'Texto visible bajo la imagen',
            seo_desc_ph: 'Descripción larga...',
            current_filename: 'Nombre actual del archivo',
            new_filename: 'Nuevo nombre (sin extensión)',
            filename_ph: 'p.ej: pastel-de-chocolate-receta',
            rename_hint: 'Se aplicará automáticamente formato SEO: minúsculas, sin acentos y guiones en lugar de espacios.',
            auto_suggest: 'Sugerencia automática',
            use_btn: 'Usar',
            resize_title: 'Redimensionar imagen',
            resize_desc: 'Opcional — reduce el tamaño en píxeles',
            max_width: 'Ancho máx',
            max_height: 'Alto máx',
            proportions_hint: 'Proporciones preservadas. Deja un campo vacío para calcularlo automáticamente.',
            replace_title: 'Reemplazar original',
            replace_desc: 'Actualiza automáticamente todos los enlaces de la web',
            optimize_now: '⚡ Optimizar ahora',
            pdf_title: 'Comprimir PDFs',
            pdf_desc: 'Reduce el peso de los PDFs de la biblioteca sin cambiar la URL ni romper enlaces.<br><strong>Requiere:</strong> GhostScript en el servidor (recomendado) o extensión Imagick de PHP.',
            pdf_quality_label: 'Calidad / DPI',
            dpi_72: '72 DPI — Muy ligero (pantalla)',
            dpi_96: '96 DPI — Recomendado (web)',
            dpi_150: '150 DPI — Alta calidad',
            dpi_300: '300 DPI — Impresión',
            pdf_replace_label: 'Reemplaza el original (la URL no cambia)',
            search_pdf_ph: '🔎 Buscar PDF...',
            compress_selected: 'Comprimir seleccionados',
            auto_title: 'Auto-optimización al subir imágenes',
            auto_desc: 'Al activar esta opción, <strong>cada imagen nueva que subas</strong> se optimizará automáticamente con la configuración elegida.',
            save_config: '💾 Guardar configuración',
            repair_paths: '🔧 Reparar imágenes con ruta rota',
            mime_fix_title: 'Reparación de tipo mime',
            mime_fix_desc: 'Detecta y repara adjuntos con extensión .webp pero mime type incorrecto (image/jpeg). Soluciona imágenes invisibles en la biblioteca de medios.',
            auto_stats_title: 'Estadísticas de auto-optimización',
            auto_history_title: 'Historial de auto-optimización',
            auto_history_hint: 'Las auto-optimizaciones se registran en la pestaña <strong>Historial</strong> (filtro 🤖 Auto-optimizadas).',
            history_title: 'Historial de cambios',
            history_filter_label: 'Filtrar por acción',
            all_actions: 'Todas las acciones',
            filter_optimize: '⚡ Optimizadas',
            filter_auto: '🤖 Auto-optimizadas',
            filter_rename: '✏️ Renombradas',
            filter_seo: '🏷️ SEO actualizado',
            filter_delete: '🗑️ Eliminadas',
            filter_pdf: '📄 PDFs comprimidos',
            date_from: 'Desde',
            date_to: 'Hasta',
            search_file: 'Buscar archivo',
            search_file_ph: 'nombre del archivo...',
            history_load: '🔄 Cargar / filtrar',
            clear_30: '🗑️ Limpiar >30 días',
            clear_all: '🗑️ Limpiar todo',
            auto_clean_title: '🕐 Limpieza automática del historial',
            retention_days_label: 'Conservar:',
            retention_days_unit: 'días',
            purge_interval_label: 'Comprobación:',
            interval_daily: 'Cada día',
            interval_weekly: 'Cada semana',
            interval_monthly: 'Cada mes',
            retention_zero_hint: '0 días = desactivado',
            retention_saved: '¡Guardado!',
            retention_invalid: 'Valor inválido. Usa 0 para desactivar, o entre 1 y 3650 días.',
            save_btn: '💾 Guardar',
            url_title: '🔗 Detector de URLs Inconsistentes',
            url_desc: 'Detecta URLs de imágenes en entradas y páginas que <strong>apuntan a archivos que ya no existen</strong> en el servidor — normalmente por conversiones de JPG a WebP. Puede actualizarlas automáticamente en toda la base de datos.',
            url_obsolete: 'URL obsoleta → nueva correcta',
            scan_web: '🔍 Escanear toda la web',
            select_fixable: 'Seleccionar reparables',
            fix_selected: '✅ Reparar seleccionadas',
            scan_rogue: '🔍 Escanear archivos extra',
            rename_btn: '✏️ Renombrar archivo',
            files_label: 'archivos',
            remaining_label: 'restantes',
            open_title: 'Abrir',
            compressed_tag: 'Comprimido',
            pdf_preview_btn: '👁 Ver',
            pdf_preview_title: 'Vista previa PDF',
            pdf_open_tab: 'Abrir en pestaña nueva',
            pdf_preview_fallback: 'Si la vista previa no se carga en el navegador, abre el PDF en una pestaña nueva.',
            non_compressible_tag: 'No comprimible',
            webp_label: 'WebP',
            file_unit_suffix: 'archivo(s)?',
            hdr_file: 'Archivo',
            hdr_size: 'Tamaño',
            hdr_savings: 'Ahorro',
            hdr_date: 'Fecha',
            hdr_image: 'Imagen',
            hdr_action: 'Acción',
            hdr_details: 'Detalles',
            hdr_user: 'Usuario',
            system_user: 'Sistema',
            action_optimize: 'Optimizada',
            action_auto_optimize: 'Auto-optimizada',
            action_rename: 'Renombrada',
            action_seo_update: 'SEO actualizado',
            action_delete: 'Eliminada',
            action_pdf_compress: 'PDF comprimido',
            action_revert: 'Revertida',
            action_dup_merge_rewrite: 'Fusión: reapuntada',
            action_dup_merge_delete: 'Fusión: eliminada',
            action_dup_delete_unused: 'Duplicado eliminado',
            action_dup_detach: 'Desvinculada',
            hist_merged_into: 'Fusionada con',
            hist_replaced_by: 'Sustituida por',
            hist_no_refs_found: 'Sin referencias encontradas',
            hist_detached_from: 'Desvinculada de',
            selected_of: 'seleccionados de',
            fixable_label: 'reparables',
            appears_in: 'Aparece en',
            occurrence: 'ocurrencia',
            occurrences: 'ocurrencias',
            url_items_suffix: 'URL(s)?',
            skipped_suffix: 'omitidas',
            error_prefix: 'Error: ',
            ghost_found_singular: 'adjunto fantasma encontrado.',
            ghost_found_plural: 'adjuntos fantasma encontrados.',
            ghost_confirm_suffix: 'adjunto(s) fantasma? Esto elimina los registros de la base de datos.',
            post_label: 'Entrada',
            page_label: 'Página'
            ,results_title: 'Resultados'
            ,clear_btn: 'Limpiar'
            ,scanning_server_files: 'Escaneando archivos del servidor...'
            ,edit_image_title: 'Editar imagen'
            ,repair_images_desc: '🔧 <strong style="color:var(--imp-warn);">Reparación de imágenes</strong> — Si tienes imágenes auto-optimizadas a WebP que no aparecen en SEO y Nombres ni en Optimizar, este botón repara la ruta en la base de datos.'
            ,ghost_delete_desc: '🗑️ <strong style="color:var(--imp-danger);">Eliminar adjuntos fantasma</strong> — Detecta registros en la BD que apuntan a archivos que <strong>no existen físicamente</strong> en disco. Los elimina por completo: post, metadatos y miniaturas de BD. <em>No toca ningún archivo físico.</em>'
            ,loading_stats: 'Cargando estadísticas...'
            ,history_prompt_load: 'Carga el historial pulsando "Cargar".'
            ,scanning_posts_pages: 'Escaneando contenidos... puede tardar unos segundos.'
            ,donate_support: '☕ Apoya este plugin'
            ,theme_day: 'Modo día'
            ,theme_night: 'Modo noche'
            ,theme_auto: 'Modo auto'
            ,theme_auto_hint: 'Según amanecer y ocaso (cambia con la estación)'
            ,auto_source_formats_label: 'Formatos de origen para auto-convertir'
            ,auto_src_jpg: 'JPG/JPEG'
            ,auto_src_png: 'PNG'
            ,auto_src_webp: 'WEBP'
            ,auto_src_gif: 'GIF (solo estático)'
            ,auto_src_bmp: 'BMP'
            ,auto_src_tiff: 'TIFF'
            ,modal_seo: 'SEO'
            ,preset_fhd: 'Full HD'
            ,preset_hd: 'HD'
            ,preset_web: 'Web'
            ,preset_medium: 'Medio'
            ,preset_small: 'Pequeño'
            ,rogue_reason_double_extension: 'Doble extensión (.jpg.webp)'
            ,rogue_reason_tso_backup: 'Backup TSO'
            ,rogue_reason_tso_temp: 'Temporal TSO'
            ,rogue_reason_tso_pdf_compressed: 'PDF comprimido TSO'
            ,rogue_reason_generic_backup: 'Backup genérico (.bk)'
            ,rogue_reason_unregistered_wp_db: 'Archivo no registrado en la BD de WordPress'
            ,rogue_delete_allowlist_note: 'Puedes borrar archivos escaneados durante 24 h (solo el admin que escaneó). Vuelve a escanear si falla el borrado.'
            ,rogue_rescan_required: 'Algunos archivos se omitieron: vuelve a escanear antes de borrar (la lista caduca a las 24 h).'
            ,tab_dashboard: 'Resumen'
            ,dash_title: 'Resumen del sitio'
            ,dash_desc: 'Vista rápida de la salud de las imágenes, motores disponibles y acciones pendientes.'
            ,dash_total_images: 'Imágenes en la biblioteca'
            ,dash_missing_alt: 'Texto alt ausente'
            ,dash_backups: 'Copias de seguridad TSO'
            ,dash_saved: 'Espacio ahorrado'
            ,dash_operations: 'operaciones del plugin'
            ,dash_auto_on: 'Auto-optimizar ACTIVADO'
            ,dash_auto_off: 'Auto-optimizar DESACTIVADO'
            ,dash_auto_label: 'Auto-optimizar'
            ,dash_auto_status_on: 'ACTIVADO'
            ,dash_auto_status_off: 'DESACTIVADO'
            ,dash_auto_format_sub: 'Formato de salida: %s'
            ,dash_auto_disabled_hint: 'Desactivado — activa en la pestaña Auto'
            ,dash_alt_title: 'Imágenes sin alt (o alt genérico)'
            ,dash_alt_desc: 'Sugiere alt desde el título o nombre de archivo. Puedes editar el texto en «Alt sugerido» y guardarlo con Enter o ✓. «Rellenar alt seleccionadas» usa el texto editado.'
            ,dash_alt_save: 'Guardar'
            ,dash_alt_edit: 'Editar alt'
            ,dash_alt_accept: 'Aceptar alt sugerido'
            ,dash_alt_cancel: 'Cancelar'
            ,dash_alt_used_only: 'Solo usadas en contenido'
            ,dash_alt_used_only_tip: 'Muestra solo imágenes referenciadas en entradas, páginas, imágenes destacadas, widgets o campos personalizados. Oculta subidas no usadas de la biblioteca.'
            ,dash_alt_fill: 'Rellenar alt seleccionadas'
            ,dash_alt_fill_tip: 'Escribe el alt sugerido (título si es útil, si no nombre de archivo humanizado) en cada imagen seleccionada. Omite las que ya tienen un alt útil.'
            ,dash_alt_suggested: 'Alt sugerido'
            ,dash_alt_file_col: 'Archivo'
            ,dash_alt_used_in: 'Usada en'
            ,dash_alt_updated: 'textos alt actualizados.'
            ,dash_alt_skipped: 'omitidas (ya tenían alt).'
            ,dash_alt_manual_hint: 'Escribe texto alt…'
            ,dash_engine_gd: 'GD WebP'
            ,dash_engine_avif: 'GD AVIF'
            ,dash_engine_gs: 'GhostScript'
            ,dash_engine_imagick: 'Imagick'
            ,dash_engine_info_title: 'Motores del servidor'
            ,dash_engine_gd_desc: 'PHP GD con lectura/escritura WebP. Necesario para convertir a WebP y la mayoría de optimizaciones.'
            ,dash_engine_avif_desc: 'PHP GD con lectura/escritura AVIF. Solo hace falta si eliges AVIF como formato de salida.'
            ,dash_engine_gs_desc: 'Herramienta de línea de comandos para comprimir PDFs en la pestaña PDFs.'
            ,dash_engine_imagick_desc: 'Extensión PHP ImageMagick. Respaldo para comprimir PDFs y algunas tareas de imagen.'
            ,dash_engine_available: 'Disponible en este servidor.'
            ,dash_engine_unavailable: 'No disponible en este servidor.'
            ,dash_engine_why_fail: 'Por qué no funciona'
            ,dash_engine_close: 'Cerrar'
            ,dash_go_orphans: 'Buscar huérfanas'
            ,dash_go_urls: 'Reparar URLs'
            ,dash_alt_all_ok: 'Todas las imágenes tienen un alt útil.'
            ,dash_queue_title: 'Cola en segundo plano'
            ,dash_queue_desc: 'Las optimizaciones masivas se ejecutan en segundo plano vía WP-Cron (5 imágenes por lote).'
            ,dash_queue_cancel: 'Cancelar pendientes / miniaturas'
            ,dash_queue_empty: 'La cola está vacía.'
            ,dash_queue_done: 'hechas'
            ,dash_queue_pending: 'pendientes'
            ,dash_queue_processing: 'procesando'
            ,dash_queue_thumbs: 'miniaturas pendientes'
            ,dash_queue_errors: 'errores'
            ,dash_queue_queued: 'En cola para procesar en segundo plano...'
            ,dash_queue_queued_n: 'imágenes en cola.'
            ,dash_queue_skipped_n: 'omitidas (ocupadas o ya en cola).'
            ,dash_backup_title: 'Retención de copias de seguridad'
            ,dash_backup_desc: 'Elimina automáticamente las copias TSO en uploads/tso-image-master/ (0 = desactivado).'
            ,dash_backup_days: 'Conservar copias (días)'
            ,dash_backup_max: 'Tamaño máximo total (MB, 0 = ilimitado)'
            ,dash_backup_purge: 'Purgar ahora'
            ,dash_backup_purge_noop: 'La retención está desactivada (0 días y 0 MB). Nada que purgar.'
            ,dash_dup_title: 'Imágenes duplicadas'
            ,dash_dup_scan: 'Escanear duplicados'
            ,dash_dup_none: 'No se han encontrado grupos de duplicados.'
            ,dash_dup_groups: 'grupos de duplicados'
            ,dash_dup_wasted: 'Espacio desperdiciado'
            ,dash_dup_explain: 'Cada grupo son archivos idénticos (mismo MD5) registrados más de una vez en la Biblioteca de medios. Conserva una entrada y elimina el resto desde Medios de WordPress.'
            ,dash_dup_rescan_hint: 'Estos datos son del último escaneo. Si acabas de editar una entrada, vuelve a pulsar «Escanear duplicados» para ver el estado actual.'
            ,dash_dup_group_label: 'Archivo idéntico'
            ,dash_dup_entries: 'entradas'
            ,dash_dup_wasted_in_group: 'desperdiciados en este grupo'
            ,dash_dup_same_name: 'Mismo nombre de archivo pero IDs de medios diferentes.'
            ,dash_dup_media_id: 'ID Medios'
            ,dash_dup_upload_path: 'Ubicación'
            ,dash_dup_uploaded: 'Subida'
            ,dash_dup_dimensions: 'Tamaño'
            ,dash_dup_refs: 'Referenciada en'
            ,dash_dup_refs_none: 'No referenciada en el contenido (puede ser huérfana)'
            ,dash_dup_refs_more: 'más'
            ,dash_dup_open_im: 'Abrir en Image Master'
            ,dash_dup_open_wp: 'Medios WP'
            ,dash_dup_item_title: 'Título'
            ,dash_scanned: 'escaneadas'
            ,dash_dup_keep: 'Conserva esta (usada en el contenido)'
            ,dash_dup_attached_only: 'Solo adjuntada (no se muestra en el contenido)'
            ,dash_dup_delete_ok: 'Se puede eliminar (duplicado no usado)'
            ,dash_dup_detach: 'Desvincular'
            ,dash_dup_detach_confirm: '¿Desvincular este archivo de la entrada a la que está adjunto en la Biblioteca de medios? Esto no elimina el archivo — podrás eliminarlo después si resulta que no se usa en ningún otro sitio.'
            ,dash_dup_detaching: 'Desvinculando...'
            ,dash_dup_detached_ok: 'Desvinculada.'
            ,dash_dup_used_direct: 'Usada en el contenido'
            ,dash_dup_used_indirect: 'Solo coincidencia de nombre (este ID no está en el código)'
            ,dash_dup_active_id: 'ID activo en la entrada'
            ,dash_dup_open_post: 'Abrir entrada'
            ,dash_dup_merge: 'Fusionar duplicados'
            ,dash_dup_merge_hint: 'Conserva los archivos marcados abajo (puedes marcar más de uno), reapunta todo el contenido que usaba el resto y elimina los archivos redundantes.'
            ,dash_dup_merge_all_kept: 'Todos los archivos están marcados para conservar — no hay nada que fusionar ni eliminar.'
            ,dash_dup_merge_none_kept: 'Marca al menos un archivo para conservar antes de fusionar.'
            ,dash_dup_keep_this: 'Conservar este'
            ,dash_dup_merge_confirm: 'Esto reescribirá todas las entradas que usan las otras copias para que apunten al archivo conservado, y luego eliminará los archivos redundantes. No se puede deshacer automáticamente. ¿Continuar?'
            ,dash_dup_merging: 'Fusionando...'
            ,dash_dup_posts_updated: 'entradas actualizadas'
            ,dash_dup_files_deleted: 'archivos redundantes eliminados'
            ,dash_dup_kept_review: 'dejados sin tocar (todavía referenciados en algún sitio que la herramienta no puede reescribir con seguridad — revísalos a mano)'
            ,dash_dup_delete: 'Eliminar'
            ,dash_dup_delete_confirm: '¿Eliminar este archivo definitivamente? El plugin volverá a comprobar justo antes de borrar que nada lo referencia. No se puede deshacer.'
            ,dash_dup_deleting: 'Eliminando...'
            ,dash_dup_deleted_ok: 'Archivo eliminado.'
            ,dash_dup_delete_blocked: 'No eliminado: ha aparecido una referencia a este archivo desde el último escaneo. Vuelve a escanear para ver el uso actual.'
            ,dash_heavy_btn: 'Imágenes más pesadas'
            ,auto_skip_kb_label: 'Omitir auto-optimizar si WebP/AVIF ≤ (KB, 0 = off)'
            ,auto_fill_alt_label: 'Rellenar alt ausente al subir'
            ,fmt_avif: 'AVIF'
            ,fmt_png: 'PNG'
            ,per_page_21: '📄 21 / página'
            ,per_page_35: '📄 35 / página'
            ,per_page_49: '📄 49 / página'
            ,per_page_70: '📄 70 / página'
            ,per_page_105: '📄 105 / página'
            ,hist_seo_bulk: 'Rellenado masivo'
            ,hist_seo_auto: 'Al subir'
        },
        en: {
            tab_optimize: 'Optimize',
            tab_orphans: 'Orphan Images',
            tab_seo: 'SEO & Names',
            tab_pdf: 'PDFs',
            tab_auto: 'Auto-optimization',
            tab_history: 'History',
            tab_urls: 'URLs',
            opt_config_title: 'Optimization Settings',
            format_label: 'Output format',
            fmt_webp: 'WebP (recommended)',
            fmt_keep: 'Keep original format',
            fmt_keep_current: 'Keep current format',
            quality_label: 'Quality',
            search_image_ph: '🔎 Search image...',
            select_all: 'Select all',
            deselect: 'Deselect',
            bulk_optimize: 'Optimize selected',
            bulk_replace_label: 'Replace the original and update all links',
            orphans_title: 'Find Orphan Images',
            orphans_desc: 'Images in the media library that are <strong>not referenced</strong> in any post, page, widget or metadata.',
            orphan_limit_label: 'Images to scan per batch',
            all_slow: 'All (slow)',
            scan_now: '🔍 Scan now',
            delete_selected: 'Delete selected',
            rogue_title: '🗂 Extra files in uploads',
            rogue_desc: 'Scans files in <code>uploads/</code> not registered in WordPress: <strong>TSO backup copies</strong> (<code>_tso_im_backup</code>), temp files (<code>_tso_im_opt</code>), double extensions (<code>.jpg.webp</code>), old backups (<code>.bk</code>), etc. Review before deleting backup copies.',
            sort_size: '📦 Sort by size (largest first)',
            sort_date: '📅 Sort by creation date',
            sort_modified: '✏️ Sort by modification date',
            modal_rename: 'Rename',
            modal_optimize: 'Optimize',
            seo_title_label: 'Title',
            seo_alt_label: 'Alt text',
            seo_caption_label: 'Caption',
            seo_desc_label: 'Description',
            seo_title_ph: 'Descriptive image title',
            seo_alt_ph: 'Accessibility and SEO description',
            seo_caption_ph: 'Visible text below the image',
            seo_desc_ph: 'Long description...',
            current_filename: 'Current filename',
            new_filename: 'New name (without extension)',
            filename_ph: 'e.g: chocolate-cake-recipe',
            rename_hint: 'SEO format is applied automatically: lowercase, no accents, hyphens instead of spaces.',
            auto_suggest: 'Automatic suggestion',
            use_btn: 'Use',
            resize_title: 'Resize image',
            resize_desc: 'Optional — reduce pixel dimensions',
            max_width: 'Max width',
            max_height: 'Max height',
            proportions_hint: 'Proportions are preserved. Leave one field empty to auto-calculate.',
            replace_title: 'Replace original',
            replace_desc: 'Automatically updates all links on the website',
            optimize_now: '⚡ Optimize now',
            pdf_title: 'Compress PDFs',
            pdf_desc: 'Reduce PDF file size in the media library without changing URLs or breaking links.<br><strong>Requires:</strong> GhostScript on server (recommended) or PHP Imagick extension.',
            pdf_quality_label: 'Quality / DPI',
            dpi_72: '72 DPI — Very light (screen)',
            dpi_96: '96 DPI — Recommended (web)',
            dpi_150: '150 DPI — High quality',
            dpi_300: '300 DPI — Print',
            pdf_replace_label: 'Replace original (URL unchanged)',
            search_pdf_ph: '🔎 Search PDF...',
            compress_selected: 'Compress selected',
            auto_title: 'Auto-optimization on upload',
            auto_desc: 'When enabled, <strong>every new uploaded image</strong> is optimized automatically with the selected settings.',
            save_config: '💾 Save settings',
            repair_paths: '🔧 Repair images with broken path',
            mime_fix_title: 'Mime type repair',
            mime_fix_desc: 'Detect and repair attachments with .webp extension but incorrect mime type (image/jpeg). Fixes invisible images in Media Library.',
            auto_stats_title: 'Auto-optimization statistics',
            auto_history_title: 'Auto-optimization history',
            auto_history_hint: 'Auto-optimizations are logged under the <strong>History</strong> tab (filter 🤖 Auto-optimized).',
            history_title: 'Change history',
            history_filter_label: 'Filter by action',
            all_actions: 'All actions',
            filter_optimize: '⚡ Optimized',
            filter_auto: '🤖 Auto-optimized',
            filter_rename: '✏️ Renamed',
            filter_seo: '🏷️ SEO updated',
            filter_delete: '🗑️ Deleted',
            filter_pdf: '📄 Compressed PDFs',
            date_from: 'From',
            date_to: 'To',
            search_file: 'Search file',
            search_file_ph: 'filename...',
            history_load: '🔄 Load / filter',
            clear_30: '🗑️ Clean >30 days',
            clear_all: '🗑️ Clean all',
            auto_clean_title: '🕐 Automatic history cleanup',
            retention_days_label: 'Keep:',
            retention_days_unit: 'days',
            purge_interval_label: 'Check:',
            interval_daily: 'Daily',
            interval_weekly: 'Weekly',
            interval_monthly: 'Monthly',
            retention_zero_hint: '0 days = disabled',
            retention_saved: 'Saved!',
            retention_invalid: 'Invalid value. Use 0 to disable, or between 1 and 3650 days.',
            save_btn: '💾 Save',
            url_title: '🔗 Inconsistent URL Detector',
            url_desc: 'Detects image URLs in posts/pages that <strong>point to files that no longer exist</strong> on the server — typically after JPG to WebP conversions. It can update links automatically across the database.',
            url_obsolete: 'Obsolete URL → corrected URL',
            scan_web: '🔍 Scan entire website',
            select_fixable: 'Select fixable',
            fix_selected: '✅ Fix selected',
            scan_rogue: '🔍 Scan extra upload files',
            rename_btn: '✏️ Rename file',
            files_label: 'files',
            remaining_label: 'remaining',
            open_title: 'Open',
            compressed_tag: 'Compressed',
            pdf_preview_btn: '👁 View',
            pdf_preview_title: 'PDF preview',
            pdf_open_tab: 'Open in new tab',
            pdf_preview_fallback: 'If the preview does not load in your browser, open the PDF in a new tab.',
            non_compressible_tag: 'Not compressible',
            webp_label: 'WebP',
            file_unit_suffix: 'file(s)?',
            hdr_file: 'File',
            hdr_size: 'Size',
            hdr_savings: 'Savings',
            hdr_date: 'Date',
            hdr_image: 'Image',
            hdr_action: 'Action',
            hdr_details: 'Details',
            hdr_user: 'User',
            system_user: 'System',
            action_optimize: 'Optimized',
            action_auto_optimize: 'Auto-optimized',
            action_rename: 'Renamed',
            action_seo_update: 'SEO updated',
            action_delete: 'Deleted',
            action_pdf_compress: 'PDF compressed',
            action_revert: 'Reverted',
            action_dup_merge_rewrite: 'Merge: repointed',
            action_dup_merge_delete: 'Merge: deleted',
            action_dup_delete_unused: 'Duplicate deleted',
            action_dup_detach: 'Unlinked',
            hist_merged_into: 'Merged into',
            hist_replaced_by: 'Replaced by',
            hist_no_refs_found: 'No references found',
            hist_detached_from: 'Unlinked from',
            selected_of: 'selected of',
            fixable_label: 'fixable',
            appears_in: 'Appears in',
            occurrence: 'occurrence',
            occurrences: 'occurrences',
            url_items_suffix: 'URL(s)?',
            skipped_suffix: 'skipped',
            error_prefix: 'Error: ',
            ghost_found_singular: 'ghost attachment found.',
            ghost_found_plural: 'ghost attachments found.',
            ghost_confirm_suffix: 'ghost attachment(s)? This removes database records.',
            post_label: 'Post',
            page_label: 'Page'
            ,results_title: 'Results'
            ,clear_btn: 'Clear'
            ,scanning_server_files: 'Scanning server files...'
            ,edit_image_title: 'Edit image'
            ,repair_images_desc: '🔧 <strong style="color:var(--imp-warn);">Image repair</strong> — If you have auto-optimized WebP images that do not appear in SEO & Names or Optimize, this button repairs their database path.'
            ,ghost_delete_desc: '🗑️ <strong style="color:var(--imp-danger);">Delete ghost attachments</strong> — Detects DB records that point to files that <strong>do not physically exist</strong> on disk. Deletes post, metadata and DB thumbnails. <em>No physical files are touched.</em>'
            ,loading_stats: 'Loading statistics...'
            ,history_prompt_load: 'Load history by clicking "Load".'
            ,scanning_posts_pages: 'Scanning content items... this may take a few seconds.'
            ,donate_support: '☕ Support this plugin'
            ,theme_day: 'Day mode'
            ,theme_night: 'Night mode'
            ,theme_auto: 'Auto mode'
            ,theme_auto_hint: 'Follows sunrise and sunset (changes with the seasons)'
            ,auto_source_formats_label: 'Source formats for auto-conversion'
            ,auto_src_jpg: 'JPG/JPEG'
            ,auto_src_png: 'PNG'
            ,auto_src_webp: 'WEBP'
            ,auto_src_gif: 'GIF (static only)'
            ,auto_src_bmp: 'BMP'
            ,auto_src_tiff: 'TIFF'
            ,modal_seo: 'SEO'
            ,preset_fhd: 'Full HD'
            ,preset_hd: 'HD'
            ,preset_web: 'Web'
            ,preset_medium: 'Medium'
            ,preset_small: 'Small'
            ,rogue_reason_double_extension: 'Double extension (.jpg.webp)'
            ,rogue_reason_tso_backup: 'TSO backup'
            ,rogue_reason_tso_temp: 'TSO temporary file'
            ,rogue_reason_tso_pdf_compressed: 'TSO compressed PDF'
            ,rogue_reason_generic_backup: 'Generic backup (.bk)'
            ,rogue_reason_unregistered_wp_db: 'File not registered in WordPress DB'
            ,rogue_delete_allowlist_note: 'You can delete scanned files for 24 hours (current admin only). Rescan if delete fails.'
            ,rogue_rescan_required: 'Some files were skipped: run Scan again before deleting (allowlist expires after 24 hours).'
            ,tab_dashboard: 'Overview'
            ,dash_title: 'Site overview'
            ,dash_desc: 'Quick view of image health, available engines, and pending actions.'
            ,dash_total_images: 'Images in library'
            ,dash_missing_alt: 'Missing alt text'
            ,dash_backups: 'TSO backups'
            ,dash_saved: 'Space saved'
            ,dash_operations: 'operations'
            ,dash_auto_on: 'Auto-optimize ON'
            ,dash_auto_off: 'Auto-optimize OFF'
            ,dash_auto_label: 'Auto-optimize'
            ,dash_auto_status_on: 'ON'
            ,dash_auto_status_off: 'OFF'
            ,dash_auto_format_sub: 'Output format: %s'
            ,dash_auto_disabled_hint: 'Disabled — enable in Auto tab'
            ,dash_alt_title: 'Images without alt (or generic alt)'
            ,dash_alt_desc: 'Suggests alt from title or filename. Edit the «Suggested alt» field and save with Enter or ✓. «Fill alt for selected» uses your edited text.'
            ,dash_alt_save: 'Save'
            ,dash_alt_edit: 'Edit alt'
            ,dash_alt_accept: 'Accept suggested alt'
            ,dash_alt_cancel: 'Cancel'
            ,dash_alt_used_only: 'Only used in content'
            ,dash_alt_used_only_tip: 'Shows only images referenced in posts, pages, featured images, widgets, or custom fields. Hides unused library uploads.'
            ,dash_alt_fill: 'Fill alt for selected'
            ,dash_alt_fill_tip: 'Writes the suggested alt (title if useful, otherwise humanized filename) into each selected image. Skips images that already have useful alt text.'
            ,dash_alt_suggested: 'Suggested alt'
            ,dash_alt_file_col: 'File'
            ,dash_alt_used_in: 'Used in'
            ,dash_alt_updated: 'alt texts updated.'
            ,dash_alt_skipped: 'skipped (already had alt).'
            ,dash_alt_manual_hint: 'Write alt text…'
            ,dash_engine_gd: 'GD WebP'
            ,dash_engine_avif: 'GD AVIF'
            ,dash_engine_gs: 'GhostScript'
            ,dash_engine_imagick: 'Imagick'
            ,dash_engine_info_title: 'Server engines'
            ,dash_engine_gd_desc: 'PHP GD with WebP read/write. Required for WebP conversion and most image optimization.'
            ,dash_engine_avif_desc: 'PHP GD with AVIF read/write. Needed only when you choose AVIF as output format.'
            ,dash_engine_gs_desc: 'Command-line tool used to compress PDF files in the PDFs tab.'
            ,dash_engine_imagick_desc: 'ImageMagick PHP extension. Fallback for PDF compression and some image tasks.'
            ,dash_engine_available: 'Available on this server.'
            ,dash_engine_unavailable: 'Not available on this server.'
            ,dash_engine_why_fail: 'Why it is not working'
            ,dash_engine_close: 'Close'
            ,dash_go_orphans: 'Find orphans'
            ,dash_go_urls: 'Fix URLs'
            ,dash_alt_all_ok: 'All images have useful alt text.'
            ,dash_queue_title: 'Background queue'
            ,dash_queue_desc: 'Bulk optimize jobs run in the background via WP-Cron (5 images per batch).'
            ,dash_queue_cancel: 'Cancel pending / thumbnails'
            ,dash_queue_empty: 'Queue is empty.'
            ,dash_queue_done: 'done'
            ,dash_queue_pending: 'pending'
            ,dash_queue_processing: 'processing'
            ,dash_queue_thumbs: 'thumbnails pending'
            ,dash_queue_errors: 'errors'
            ,dash_queue_queued: 'Queued for background processing...'
            ,dash_queue_queued_n: 'images queued.'
            ,dash_queue_skipped_n: 'skipped (already busy or in queue).'
            ,dash_backup_title: 'Backup retention'
            ,dash_backup_desc: 'Auto-delete TSO backups under uploads/tso-image-master/ (0 = disabled).'
            ,dash_backup_days: 'Keep backups (days)'
            ,dash_backup_max: 'Max total size (MB, 0 = unlimited)'
            ,dash_backup_purge: 'Purge now'
            ,dash_backup_purge_noop: 'Retention is disabled (0 days and 0 MB). Nothing to purge.'
            ,dash_dup_title: 'Duplicate images'
            ,dash_dup_scan: 'Scan duplicates'
            ,dash_dup_none: 'No duplicate groups found.'
            ,dash_dup_groups: 'duplicate groups'
            ,dash_dup_wasted: 'Wasted space'
            ,dash_dup_explain: 'Each group is identical file content (same MD5) registered more than once in the Media Library. Keep one entry and delete the rest from WordPress Media.'
            ,dash_dup_rescan_hint: 'This data is from the last scan. If you just edited a post, click "Scan duplicates" again to see the current status.'
            ,dash_dup_group_label: 'Identical file'
            ,dash_dup_entries: 'entries'
            ,dash_dup_wasted_in_group: 'wasted in this group'
            ,dash_dup_same_name: 'Same filename but different Media Library IDs.'
            ,dash_dup_media_id: 'Media ID'
            ,dash_dup_upload_path: 'Location'
            ,dash_dup_uploaded: 'Uploaded'
            ,dash_dup_dimensions: 'Dimensions'
            ,dash_dup_refs: 'Referenced in'
            ,dash_dup_refs_none: 'Not referenced in content (may be orphan)'
            ,dash_dup_refs_more: 'more'
            ,dash_dup_open_im: 'Open in Image Master'
            ,dash_dup_open_wp: 'WP Media'
            ,dash_dup_item_title: 'Title'
            ,dash_scanned: 'scanned'
            ,dash_dup_keep: 'Keep this one (used in content)'
            ,dash_dup_attached_only: 'Only attached (not shown in content)'
            ,dash_dup_delete_ok: 'Safe to delete (unused duplicate)'
            ,dash_dup_detach: 'Unlink'
            ,dash_dup_detach_confirm: 'Unlink this file from the post it\'s attached to in the Media Library? This does not delete the file — you\'ll be able to delete it afterwards if it turns out to be unused everywhere else.'
            ,dash_dup_detaching: 'Unlinking...'
            ,dash_dup_detached_ok: 'Unlinked.'
            ,dash_dup_used_direct: 'Used in content'
            ,dash_dup_used_indirect: 'Filename match only (this ID is not in the code)'
            ,dash_dup_active_id: 'Active ID in post'
            ,dash_dup_open_post: 'Open post'
            ,dash_dup_merge: 'Merge duplicates'
            ,dash_dup_merge_hint: 'Keeps the file(s) checked below (you can check more than one), repoints every post/page that used the rest to the first kept file, then deletes the redundant files.'
            ,dash_dup_merge_all_kept: 'Every file is marked to keep — there is nothing left to merge or delete.'
            ,dash_dup_merge_none_kept: 'Check at least one file to keep before merging.'
            ,dash_dup_keep_this: 'Keep this one'
            ,dash_dup_merge_confirm: 'This rewrites every post/page that uses the other copies so they point at the kept file instead, then deletes the now-redundant files. This cannot be undone automatically. Continue?'
            ,dash_dup_merging: 'Merging...'
            ,dash_dup_posts_updated: 'posts/pages updated'
            ,dash_dup_files_deleted: 'redundant files deleted'
            ,dash_dup_kept_review: 'left untouched (still referenced somewhere the tool could not safely rewrite — review manually)'
            ,dash_dup_delete: 'Delete'
            ,dash_dup_delete_confirm: 'Delete this file permanently? The plugin will double-check right before deleting that nothing references it. This cannot be undone.'
            ,dash_dup_deleting: 'Deleting...'
            ,dash_dup_deleted_ok: 'File deleted.'
            ,dash_dup_delete_blocked: 'Not deleted: a reference to this file appeared since the last scan. Rescan to see current usage.'
            ,dash_heavy_btn: 'Largest images'
            ,auto_skip_kb_label: 'Skip auto-optimize if WebP/AVIF ≤ (KB, 0 = off)'
            ,auto_fill_alt_label: 'Fill missing alt text on upload'
            ,fmt_avif: 'AVIF'
            ,fmt_png: 'PNG'
            ,per_page_21: '📄 21 per page'
            ,per_page_35: '📄 35 per page'
            ,per_page_49: '📄 49 per page'
            ,per_page_70: '📄 70 per page'
            ,per_page_105: '📄 105 per page'
            ,hist_seo_bulk: 'Bulk fill'
            ,hist_seo_auto: 'On upload'
        }
    };

    // Strings from wp_localize_script (WordPress locale). Merged so plugin UI language overrides WP admin language.
    (function mergeLocalizedStringsIntoI18n() {
        var WP = {
            es: {
                confirm_delete: '¿Eliminar las imágenes seleccionadas? Esta acción es irreversible.',
                confirm_del_backup: '¿Eliminar el backup? No podrás revertir la imagen.',
                confirm_revert: '¿Revertir al original? La versión optimizada se perderá.',
                confirm_delete_rogue: 'Eliminar',
                no_selection: 'Selecciona al menos una imagen.',
                processing: 'Procesando...',
                save_ok: '¡Guardado!',
                save_seo: 'Guardar SEO',
                edit_image: 'Editar',
                scan_rogue: 'Escanear archivos extra',
                delete_rogue: 'Eliminar seleccionados',
                scan_web: 'Escanear todo el sitio',
                fix_selected: 'Reparar seleccionadas',
                optimize_now: 'Optimizar ahora',
                rename_btn: 'Renombrar archivo',
                revert_btn: 'Revertir al original',
                del_backup: 'Eliminar copia de seguridad',
                backup_available: 'Copia de seguridad disponible',
                repair_paths: 'Reparar rutas rotas',
                loading_images: 'Cargando imágenes...',
                loading_pdfs: 'Cargando PDFs...',
                loading_data: 'Cargando...',
                loading_modal: 'Cargando...',
                scanning_msg: 'Escaneando...',
                no_images: 'No se han encontrado imágenes.',
                no_orphans: 'No se han encontrado imágenes huérfanas.',
                no_rogue: 'No se han encontrado archivos extra.',
                no_pdfs: 'No se han encontrado PDFs.',
                no_auto_history: 'No hay entradas de auto-optimización.',
                auto_hist_error: 'Error al cargar el historial.',
                no_history: 'No se han encontrado entradas.',
                history_empty: 'El historial está vacío.',
                url_all_ok: 'Ninguna URL rota encontrada. ¡Todo correcto!',
                url_no_results: 'Sin resultados.',
                url_click_select: 'Haz clic para seleccionar',
                url_content_label: 'URL en el contenido (obsoleta)',
                url_correct_label: 'URL correcta (archivo existente)',

                url_outdated_badge: 'Miniatura obsoleta (archivo existente en nuevo formato)',
                url_missing_badge: 'Archivo ausente — alternativa encontrada',
                url_broken_badge: 'Archivo ausente — sin solución automática',
                url_fixing: 'Reparando...',
                select_removable: 'Seleccionar eliminables',
                removable_label: 'eliminables',
                remove_selected: 'Eliminar del contenido',
                url_removing: 'Eliminando...',
                url_removed_ok: 'Referencias URL eliminadas del contenido.',
                confirm_remove_urls: '¿Eliminar las referencias URL rotas del contenido? Se borrará la etiqueta img o el enlace.',
                url_click_select_remove: 'Clic para seleccionar y eliminar',
                url_no_fix_label: 'Sin alternativa. Selecciona y elimina la referencia del contenido, o restaura el archivo manualmente.',
                posts_scanned: 'Contenidos escaneados',
                broken_urls: 'URLs rotas',
                fixable_urls: 'Reparable automáticamente',
                used_in: 'Usada en',
                orphan_confirmed: 'Huérfana confirmada: no referenciada en ningún lugar.',
                not_in_content: 'No encontrado en post_content.',
                optimized_ok: '¡Optimizada!',
                optimized_no_replace: 'Optimizada (sin reemplazar).',
                converted_bigger: 'Convertida pero más grande que el original.',
                optimizing_thumbs: 'Optimizando miniaturas...',
                thumbs_done: 'Miniaturas procesadas.',
                reverted_ok: '¡Revertida!',
                n_selected: 'seleccionadas',
                optimize_done: 'ahorrado',
                bulk_done: 'Hecho',
                bulk_processing: 'Procesando',
                pdf_compress_btn: 'Comprimir',
                pdf_timeout_msg: 'GhostScript tardó demasiado. Comprueba el FTP — puede estar comprimido. Recarga la página.',
                gs_available: 'GhostScript disponible',
                gs_none: 'Ningún motor de compresión disponible. Instala GhostScript.',
                auto_enabled: 'Auto-optimización ACTIVADA',
                auto_disabled: 'Auto-optimización desactivada',
                auto_desc_enabled: 'Las nuevas imágenes se convertirán automáticamente al subirlas.',
                auto_desc_disabled: 'Activa para optimizar automáticamente.',
                repaired_msg: 'imágenes reparadas. Recarga SEO & Nombres para verlas.',
                confirm_clean_30: '¿Eliminar entradas de hace más de 30 días?',
                confirm_clean_all: '¿Eliminar TODO el historial? Esta acción es irreversible.',
                btn_scanning: 'Escaneando...',
                btn_processing: 'Procesando...',
                btn_deleting: 'Eliminando...',
                btn_reverting: 'Revirtiendo...',
                revert_rename_mismatch: 'No se puede revertir porque cambiaste el nombre del archivo después de optimizarlo. El backup sigue en el servidor pero ya no coincide con el nombre actual (por ejemplo, eliminaste el sufijo -e172…). Opciones: restaura el nombre original y entonces podrás revertir; elimina este backup y vuelve a optimizar para generar uno nuevo; o recupera el archivo manualmente desde Imágenes huérfanas → Archivos extra en uploads.',
                btn_repairing: 'Reparando...',
                webp_ok: 'Soportado',
                webp_nok: 'No disponible (GD sin WebP)',
                enter_name: 'Introduce el nuevo nombre.',
                stat_total: 'Total de operaciones',
                stat_saved: 'Espacio liberado',
                stat_current_size: 'Tamaño actual',
                stat_real_format: 'Formato real',
                images_deleted: 'imágenes eliminadas.',
                all_rogue_deleted: 'Archivos extra seleccionados eliminados.',
                featured: 'Imagen destacada',
                no_alt_text: 'Sin texto alternativo',
                url_fixed_ok: 'URLs reparadas correctamente.',
                mime_fix_btn: '🔧 Reparar tipos MIME incorrectos',
                mime_fixed: 'adjuntos reparados.',
                mime_no_issues: 'Sin discrepancias. Todos los adjuntos están correctos.',
                scan_ghosts: 'Escanear adjuntos fantasma',
                no_ghosts: 'No se han encontrado adjuntos fantasma.',
                delete_ghosts: 'Eliminar seleccionados',
                confirm_delete_ghosts: 'Eliminar',
                deleted_msg: 'Eliminados',
                ghost_deleted_ok: 'eliminados correctamente.',
                ghost_deleted_none: 'Ninguno eliminado.',
                errors_label: 'Errores:',
                pdf_engine_warn: '⚠ GhostScript no encontrado. Usando Imagick.',
                tab_dashboard: 'Resumen',
                dash_title: 'Resumen del sitio',
                dash_desc: 'Vista rápida de la salud de las imágenes, motores disponibles y acciones pendientes.',
                dash_total_images: 'Imágenes en la biblioteca',
                dash_missing_alt: 'Texto alt ausente',
                dash_backups: 'Copias de seguridad TSO',
                dash_saved: 'Espacio ahorrado',
                dash_operations: 'operaciones del plugin',
                dash_auto_on: 'Auto-optimizar ACTIVADO',
                dash_auto_off: 'Auto-optimizar DESACTIVADO',
                dash_auto_label: 'Auto-optimizar',
                dash_auto_status_on: 'ACTIVADO',
                dash_auto_status_off: 'DESACTIVADO',
                dash_auto_format_sub: 'Formato de salida: %s',
                dash_auto_disabled_hint: 'Desactivado — activa en la pestaña Auto',
                dash_alt_title: 'Imágenes sin alt (o alt genérico)',
                dash_alt_desc: 'Sugiere alt desde el título o nombre de archivo. Puedes editar el texto en «Alt sugerido» y guardarlo con Enter o ✓. «Rellenar alt seleccionadas» usa el texto editado.',
                dash_alt_save: 'Guardar',
                dash_alt_edit: 'Editar alt',
                dash_alt_accept: 'Aceptar alt sugerido',
                dash_alt_cancel: 'Cancelar',
                dash_alt_used_only: 'Solo usadas en contenido',
                dash_alt_used_only_tip: 'Muestra solo imágenes referenciadas en entradas, páginas, imágenes destacadas, widgets o campos personalizados. Oculta subidas no usadas de la biblioteca.',
                dash_alt_fill: 'Rellenar alt seleccionadas',
                dash_alt_fill_tip: 'Escribe el alt sugerido (título si es útil, si no nombre de archivo humanizado) en cada imagen seleccionada. Omite las que ya tienen un alt útil.',
                dash_alt_suggested: 'Alt sugerido',
                dash_alt_file_col: 'Archivo',
                dash_alt_used_in: 'Usada en',
                dash_alt_updated: 'textos alt actualizados.',
                dash_alt_skipped: 'omitidas (ya tenían alt).',
                dash_alt_manual_hint: 'Escribe texto alt…',
                dash_engine_gd: 'GD WebP',
                dash_engine_avif: 'GD AVIF',
                dash_engine_gs: 'GhostScript',
                dash_engine_imagick: 'Imagick',
                dash_engine_info_title: 'Motores del servidor',
                dash_engine_gd_desc: 'PHP GD con lectura/escritura WebP. Necesario para convertir a WebP y la mayoría de optimizaciones.',
                dash_engine_avif_desc: 'PHP GD con lectura/escritura AVIF. Solo hace falta si eliges AVIF como formato de salida.',
                dash_engine_gs_desc: 'Herramienta de línea de comandos para comprimir PDFs en la pestaña PDFs.',
                dash_engine_imagick_desc: 'Extensión PHP ImageMagick. Respaldo para comprimir PDFs y algunas tareas de imagen.',
                dash_engine_available: 'Disponible en este servidor.',
                dash_engine_unavailable: 'No disponible en este servidor.',
                dash_engine_why_fail: 'Por qué no funciona',
                dash_engine_close: 'Cerrar',
                dash_go_orphans: 'Buscar huérfanas',
                dash_go_urls: 'Reparar URLs',
                dash_alt_all_ok: 'Todas las imágenes tienen un alt útil.',
                dash_queue_title: 'Cola en segundo plano',
                dash_queue_desc: 'Las optimizaciones masivas se ejecutan en segundo plano vía WP-Cron (5 imágenes por lote).',
                dash_queue_cancel: 'Cancelar pendientes / miniaturas',
                dash_queue_empty: 'La cola está vacía.',
                dash_queue_done: 'hechas',
                dash_queue_pending: 'pendientes',
                dash_queue_processing: 'procesando',
                dash_queue_errors: 'errores',
                dash_queue_queued: 'En cola para procesar en segundo plano...',
                dash_queue_queued_n: 'imágenes en cola.',
                dash_queue_skipped_n: 'omitidas (ocupadas o ya en cola).',
                dash_backup_title: 'Retención de copias de seguridad',
                dash_backup_desc: 'Elimina automáticamente las copias TSO en uploads/tso-image-master/ (0 = desactivado).',
                dash_backup_days: 'Conservar copias (días)',
                dash_backup_max: 'Tamaño máximo total (MB, 0 = ilimitado)',
                dash_backup_purge: 'Purgar ahora',
                dash_backup_purge_noop: 'La retención está desactivada (0 días y 0 MB). Nada que purgar.',
                dash_dup_title: 'Imágenes duplicadas',
                dash_dup_scan: 'Escanear duplicados',
                dash_dup_none: 'No se han encontrado grupos de duplicados.',
                dash_dup_groups: 'grupos de duplicados',
                dash_dup_wasted: 'Espacio desperdiciado',
                dash_heavy_btn: 'Imágenes más pesadas',
                auto_skip_kb_label: 'Omitir auto-optimizar si WebP/AVIF ≤ (KB, 0 = off)',
                auto_fill_alt_label: 'Rellenar alt ausente al subir',
                fmt_avif: 'AVIF',
                fmt_png: 'PNG',
                per_page_21: '📄 21 / página',
                per_page_35: '📄 35 / página',
                per_page_49: '📄 49 / página',
                per_page_70: '📄 70 / página',
                per_page_105: '📄 105 / página',
                hist_seo_bulk: 'Rellenado masivo',
                hist_seo_auto: 'Al subir',
                tab_sizes: 'Tamaños',
                sizes_title: 'Tamaños huérfanos no registrados',
                sizes_show_backups: 'Ver copias de seguridad',
                sizes_backups_title: 'Copias de seguridad guardadas',
                sizes_backups_desc: 'Se guardan dentro de uploads/tso-image-master/ y se limpian solas según la retención configurada en el Resumen.',
                sizes_backups_loading: 'Cargando...',
                sizes_no_backups: 'No se han encontrado copias de seguridad.',
                sizes_backups_count_label: 'copia(s) de seguridad',
                sizes_backups_col_date: 'Fecha',
                sizes_backups_col_count: 'Ficheros',
                sizes_backups_batch_tag: 'lote de tamaño',
                sizes_backups_download: 'Descargar',
                sizes_backups_delete: 'Eliminar',
                confirm_delete_backup: 'Eliminar permanentemente la copia de seguridad "{name}"? Esta acción NO se puede deshacer.',
                sizes_desc: 'Agrupa todos los ficheros generados por plugins/temas antiguos que ya no están registrados ni en uso actualmente en WordPress. Nunca toca el fichero original ni los demás tamaños.',
                scan_sizes: '📐 Escanear tamaños',
                sizes_scanning_msg: 'Escaneando la biblioteca de medios...',
                sizes_none_found: 'No se han encontrado ficheros de tamaño generados.',
                sizes_all_clean: 'Nada que eliminar — todos los tamaños generados están en uso o protegidos por WordPress.',
                sizes_names_label: 'nombres de tamaño',
                sizes_images_scanned: 'imágenes escaneadas',
                sizes_col_name: 'Nombre de tamaño',
                sizes_col_dimensions: 'Dimensiones',
                sizes_col_count: 'Ficheros',
                sizes_col_space: 'Espacio',
                sizes_col_registered: '¿Registrado ahora?',
                sizes_registered_yes: 'Sí',
                sizes_registered_no: 'No — huérfano',
                sizes_view_files: 'Ver ficheros',
                sizes_delete_all: 'Eliminar todas',
                sizes_delete_all_tip: 'Se guardará automáticamente una copia de seguridad zip de todos los ficheros antes de eliminarlos.',
                sizes_back: '← Volver a la lista de tamaños',
                sizes_irreversible_note: '⚠️ Eliminar ficheros de tamaño es <strong>irreversible</strong>. Si no estás seguro, prueba primero con una muestra pequeña y revisa el sitio antes de continuar con el resto.',
                sizes_select_sample: 'Seleccionar muestra (3)',
                sizes_detail_title_prefix: 'Tamaño:',
                sizes_load_more: 'Cargar más',
                sizes_deleted_msg: 'fichero(s) de tamaño eliminados',
                sizes_backup_made: 'Copia de seguridad guardada en: {path}',
                sizes_backup_failed: 'No se pudo hacer copia de seguridad antes de eliminar',
                confirm_delete_size_sample: '¿Eliminar estos {n} ficheros de "{size}" como prueba? Comprueba que tu sitio sigue funcionando bien antes de eliminar el resto.',
                confirm_delete_size_all: 'Esto eliminará permanentemente {n} ficheros de tamaño "{size}" ({bytes}). Esta acción NO se puede deshacer. ¿Continuar?',
                action_size_deleted: 'Tamaño eliminado',
                filter_size_deleted: '📐 Tamaños eliminados',
                hist_size_deleted_detail: 'Tamaño',
                sizes_protected_core: '🔒 Protegida (WP)',
                sizes_protected_core_tip: 'Generada por el propio WordPress para el icono del sitio/favicon, fuera del registro normal de tamaños — no es un resto, consérvala.'
            },
            en: {
                confirm_delete: 'Delete selected images? This cannot be undone.',
                confirm_del_backup: 'Delete backup? You will not be able to revert.',
                confirm_revert: 'Revert to original? Optimized version will be lost.',
                confirm_delete_rogue: 'Delete',
                no_selection: 'Select at least one image.',
                processing: 'Processing...',
                save_ok: 'Saved!',
                save_seo: 'Save SEO',
                edit_image: 'Edit',
                scan_rogue: 'Scan extra upload files',
                delete_rogue: 'Delete selected',
                scan_web: 'Scan entire site',
                fix_selected: 'Fix selected',
                optimize_now: 'Optimize now',
                rename_btn: 'Rename file',
                revert_btn: 'Revert to original',
                del_backup: 'Delete backup',
                backup_available: 'Backup available',
                repair_paths: 'Repair broken paths',
                loading_images: 'Loading images...',
                loading_pdfs: 'Loading PDFs...',
                loading_data: 'Loading...',
                loading_modal: 'Loading...',
                scanning_msg: 'Scanning...',
                no_images: 'No images found.',
                no_orphans: 'No orphaned images found.',
                no_rogue: 'No extra files found.',
                no_pdfs: 'No PDFs found.',
                no_auto_history: 'No auto-optimization entries.',
                auto_hist_error: 'Error loading history.',
                no_history: 'No entries found.',
                history_empty: 'History is empty.',
                url_all_ok: 'No broken URLs found. All correct!',
                url_no_results: 'No results.',
                url_click_select: 'Click to select',
                url_content_label: 'URL in content (obsolete)',
                url_correct_label: 'Correct URL (file exists)',

                url_outdated_badge: 'Obsolete thumbnail (file exists in new format)',
                url_missing_badge: 'Missing file - alternative found',
                url_broken_badge: 'Missing file - no automatic fix',
                url_fixing: 'Fixing...',
                select_removable: 'Select removable',
                removable_label: 'removable',
                remove_selected: 'Remove from content',
                url_removing: 'Removing...',
                url_removed_ok: 'URL references removed from content.',
                confirm_remove_urls: 'Remove selected broken URL references from content? The image tag or link will be deleted.',
                url_click_select_remove: 'Click to select for removal',
                url_no_fix_label: 'No alternative found. Select and remove the reference from content, or restore the file manually.',
                posts_scanned: 'Content items scanned',
                broken_urls: 'Broken URLs',
                fixable_urls: 'Auto-fixable',
                used_in: 'Used in',
                orphan_confirmed: 'Confirmed orphan: not referenced anywhere.',
                not_in_content: 'Not found in post_content.',
                optimized_ok: 'Optimized!',
                optimized_no_replace: 'Optimized (not replaced).',
                converted_bigger: 'Converted but larger than original.',
                optimizing_thumbs: 'Optimizing thumbnails...',
                thumbs_done: 'Thumbnails processed.',
                reverted_ok: 'Reverted!',
                n_selected: 'selected',
                optimize_done: 'saved',
                bulk_done: 'Done',
                bulk_processing: 'Processing',
                pdf_compress_btn: 'Compress',
                pdf_timeout_msg: 'GhostScript timed out. Check FTP - file may already be compressed. Reload the page.',
                gs_available: 'GhostScript available',
                gs_none: 'No compression engine available. Install GhostScript.',
                auto_enabled: 'Auto-optimization ENABLED',
                auto_disabled: 'Auto-optimization disabled',
                auto_desc_enabled: 'New images will be converted automatically on upload.',
                auto_desc_disabled: 'Enable to optimize automatically.',
                repaired_msg: 'images repaired. Reload SEO & Names to see them.',
                confirm_clean_30: 'Delete entries older than 30 days?',
                confirm_clean_all: 'Delete ALL history? This is irreversible.',
                btn_scanning: 'Scanning...',
                btn_processing: 'Processing...',
                btn_deleting: 'Deleting...',
                btn_reverting: 'Reverting...',
                revert_rename_mismatch: 'Cannot revert because you renamed the file after optimizing it. The backup still exists on the server but no longer matches the current filename (e.g. you removed the -e172… suffix). Options: restore the original filename and revert; delete this backup and re-optimize to create a new one; or recover the file manually from Orphan Images → Extra upload files.',
                btn_repairing: 'Repairing...',
                webp_ok: 'Supported',
                webp_nok: 'Not available (GD without WebP)',
                enter_name: 'Enter the new name.',
                stat_total: 'Total operations',
                stat_saved: 'Space freed',
                stat_current_size: 'Current size',
                stat_real_format: 'Real format',
                images_deleted: 'images deleted.',
                all_rogue_deleted: 'All selected extra files deleted!',
                featured: 'Featured image',
                no_alt_text: 'No alt text',
                url_fixed_ok: 'URLs fixed correctly.',
                mime_fix_btn: '🔧 Fix incorrect mime types',
                mime_fixed: 'attachments repaired.',
                mime_no_issues: 'No issues found. All attachments are correct.',
                scan_ghosts: 'Scan ghost attachments',
                no_ghosts: 'No ghost attachments found.',
                delete_ghosts: 'Delete selected',
                confirm_delete_ghosts: 'Delete',
                deleted_msg: 'Deleted',
                ghost_deleted_ok: 'deleted successfully.',
                ghost_deleted_none: 'None deleted.',
                errors_label: 'Errors:',
                pdf_engine_warn: '⚠ GhostScript not found. Using Imagick.',
                tab_dashboard: 'Overview',
                dash_title: 'Site overview',
                dash_desc: 'Quick view of image health, available engines, and pending actions.',
                dash_total_images: 'Images in library',
                dash_missing_alt: 'Missing alt text',
                dash_backups: 'TSO backups',
                dash_saved: 'Space saved',
                dash_operations: 'operations',
                dash_auto_on: 'Auto-optimize ON',
                dash_auto_off: 'Auto-optimize OFF',
                dash_auto_label: 'Auto-optimize',
                dash_auto_status_on: 'ON',
                dash_auto_status_off: 'OFF',
                dash_auto_format_sub: 'Output format: %s',
                dash_auto_disabled_hint: 'Disabled — enable in Auto tab',
                dash_alt_title: 'Images without alt (or generic alt)',
                dash_alt_desc: 'Suggests alt from title or filename. Edit the «Suggested alt» field and save with Enter or ✓. «Fill alt for selected» uses your edited text.',
                dash_alt_save: 'Save',
                dash_alt_edit: 'Edit alt',
                dash_alt_accept: 'Accept suggested alt',
                dash_alt_cancel: 'Cancel',
                dash_alt_used_only: 'Only used in content',
                dash_alt_used_only_tip: 'Shows only images referenced in posts, pages, featured images, widgets, or custom fields. Hides unused library uploads.',
                dash_alt_fill: 'Fill alt for selected',
                dash_alt_fill_tip: 'Writes the suggested alt (title if useful, otherwise humanized filename) into each selected image. Skips images that already have useful alt text.',
                dash_alt_suggested: 'Suggested alt',
                dash_alt_file_col: 'File',
                dash_alt_used_in: 'Used in',
                dash_alt_updated: 'alt texts updated.',
                dash_alt_skipped: 'skipped (already had alt).',
                dash_alt_manual_hint: 'Write alt text…',
                dash_engine_gd: 'GD WebP',
                dash_engine_avif: 'GD AVIF',
                dash_engine_gs: 'GhostScript',
                dash_engine_imagick: 'Imagick',
                dash_engine_info_title: 'Server engines',
                dash_engine_gd_desc: 'PHP GD with WebP read/write. Required for WebP conversion and most image optimization.',
                dash_engine_avif_desc: 'PHP GD with AVIF read/write. Needed only when you choose AVIF as output format.',
                dash_engine_gs_desc: 'Command-line tool used to compress PDF files in the PDFs tab.',
                dash_engine_imagick_desc: 'ImageMagick PHP extension. Fallback for PDF compression and some image tasks.',
                dash_engine_available: 'Available on this server.',
                dash_engine_unavailable: 'Not available on this server.',
                dash_engine_why_fail: 'Why it is not working',
                dash_engine_close: 'Close',
                dash_go_orphans: 'Find orphans',
                dash_go_urls: 'Fix URLs',
                dash_alt_all_ok: 'All images have useful alt text.',
                dash_queue_title: 'Background queue',
                dash_queue_desc: 'Bulk optimize jobs run in the background via WP-Cron (5 images per batch).',
                dash_queue_cancel: 'Cancel pending / thumbnails',
                dash_queue_empty: 'Queue is empty.',
                dash_queue_done: 'done',
                dash_queue_pending: 'pending',
                dash_queue_processing: 'processing',
                dash_queue_errors: 'errors',
                dash_queue_queued: 'Queued for background processing...',
                dash_queue_queued_n: 'images queued.',
                dash_queue_skipped_n: 'skipped (already busy or in queue).',
                dash_backup_title: 'Backup retention',
                dash_backup_desc: 'Auto-delete TSO backups under uploads/tso-image-master/ (0 = disabled).',
                dash_backup_days: 'Keep backups (days)',
                dash_backup_max: 'Max total size (MB, 0 = unlimited)',
                dash_backup_purge: 'Purge now',
                dash_backup_purge_noop: 'Retention is disabled (0 days and 0 MB). Nothing to purge.',
                dash_dup_title: 'Duplicate images',
                dash_dup_scan: 'Scan duplicates',
                dash_dup_none: 'No duplicate groups found.',
                dash_dup_groups: 'duplicate groups',
                dash_dup_wasted: 'Wasted space',
                dash_heavy_btn: 'Largest images',
                auto_skip_kb_label: 'Skip auto-optimize if WebP/AVIF ≤ (KB, 0 = off)',
                auto_fill_alt_label: 'Fill missing alt text on upload',
                fmt_avif: 'AVIF',
                fmt_png: 'PNG',
                per_page_21: '📄 21 per page',
                per_page_35: '📄 35 per page',
                per_page_49: '📄 49 per page',
                per_page_70: '📄 70 per page',
                per_page_105: '📄 105 per page',
                hist_seo_bulk: 'Bulk fill',
                hist_seo_auto: 'On upload',
                tab_sizes: 'Image Sizes',
                sizes_title: 'Orphaned, unregistered sizes',
                sizes_show_backups: 'View backups',
                sizes_backups_title: 'Saved backups',
                sizes_backups_desc: 'Saved inside uploads/tso-image-master/ and cleaned up automatically per the retention settings on the Overview tab.',
                sizes_backups_loading: 'Loading...',
                sizes_no_backups: 'No backup files found.',
                sizes_backups_count_label: 'backup file(s)',
                sizes_backups_col_date: 'Date',
                sizes_backups_col_count: 'Files',
                sizes_backups_batch_tag: 'size batch',
                sizes_backups_download: 'Download',
                sizes_backups_delete: 'Delete',
                confirm_delete_backup: 'Permanently delete backup "{name}"? This CANNOT be undone.',
                sizes_desc: 'Groups every file generated by old plugins/themes that is no longer registered or in use in WordPress. Never touches the original file or the other sizes.',
                scan_sizes: '📐 Scan sizes',
                sizes_scanning_msg: 'Scanning the media library...',
                sizes_none_found: 'No generated size files found.',
                sizes_all_clean: 'Nothing to delete — every generated size is either still in use or protected by WordPress.',
                sizes_names_label: 'size names',
                sizes_images_scanned: 'images scanned',
                sizes_col_name: 'Size name',
                sizes_col_dimensions: 'Dimensions',
                sizes_col_count: 'Files',
                sizes_col_space: 'Space',
                sizes_col_registered: 'Registered now?',
                sizes_registered_yes: 'Yes',
                sizes_registered_no: 'No — orphaned',
                sizes_view_files: 'View files',
                sizes_delete_all: 'Delete all',
                sizes_delete_all_tip: 'A backup zip of every file will be saved automatically before deleting.',
                sizes_back: '← Back to size list',
                sizes_irreversible_note: '⚠️ Deleting size files is <strong>irreversible</strong>. If unsure, try a small sample first and check your site before deleting the rest.',
                sizes_select_sample: 'Select sample (3)',
                sizes_detail_title_prefix: 'Size:',
                sizes_load_more: 'Load more',
                sizes_deleted_msg: 'size file(s) deleted',
                sizes_backup_made: 'Backup saved to: {path}',
                sizes_backup_failed: 'No backup could be made before deleting',
                confirm_delete_size_sample: 'Delete these {n} "{size}" files as a test? Check that your site still works before deleting the rest.',
                confirm_delete_size_all: 'This will permanently delete {n} "{size}" size files ({bytes}). This CANNOT be undone. Continue?',
                action_size_deleted: 'Size deleted',
                filter_size_deleted: '📐 Sizes deleted',
                hist_size_deleted_detail: 'Size',
                sizes_protected_core: '🔒 Protected (WP)',
                sizes_protected_core_tip: 'Generated by WordPress itself for the site icon/favicon, outside the normal size registry — not a leftover, keep it.'
            },
            ca: {
                confirm_delete: 'Eliminar les imatges seleccionades? Aquesta acció és irreversible.',
                confirm_del_backup: 'Eliminar el backup? No podràs revertir la imatge.',
                confirm_revert: 'Revertir a l\'original? La versió optimitzada es perdrà.',
                confirm_delete_rogue: 'Eliminar',
                no_selection: 'Selecciona almenys una imatge.',
                processing: 'Processant...',
                save_ok: 'Guardat!',
                save_seo: 'Guardar SEO',
                edit_image: 'Editar',
                scan_rogue: 'Escanejar fitxers extra',
                delete_rogue: 'Eliminar seleccionats',
                scan_web: 'Escanejar tot el lloc',
                fix_selected: 'Reparar seleccionades',
                optimize_now: 'Optimitzar ara',
                rename_btn: 'Reanomenar fitxer',
                revert_btn: 'Revertir a l\'original',
                del_backup: 'Eliminar còpia de seguretat',
                backup_available: 'Còpia de seguretat disponible',
                repair_paths: 'Reparar paths trencats',
                loading_images: 'Carregant imatges...',
                loading_pdfs: 'Carregant PDFs...',
                loading_data: 'Carregant...',
                loading_modal: 'Carregant...',
                scanning_msg: 'Escanejant...',
                no_images: 'No s\'han trobat imatges.',
                no_orphans: 'No s\'han trobat imatges òrfenes.',
                no_rogue: 'No s\'han trobat fitxers extra.',
                no_pdfs: 'No s\'han trobat PDFs.',
                no_auto_history: 'No hi ha entrades d\'auto-optimització.',
                auto_hist_error: 'Error carregant l\'historial.',
                no_history: 'No s\'han trobat entrades.',
                history_empty: 'L\'historial és buit.',
                url_all_ok: 'Cap URL trencada trobada. Tot correcte!',
                url_no_results: 'Cap resultat.',
                url_click_select: 'Fes clic per seleccionar',
                url_content_label: 'URL al contingut (obsoleta)',
                url_correct_label: 'URL correcta (fitxer existent)',

                url_outdated_badge: 'Miniatura obsoleta (fitxer existent en nou format)',
                url_missing_badge: 'Fitxer absent — alternativa trobada',
                url_broken_badge: 'Fitxer absent — sense solució automàtica',
                url_fixing: 'Reparant...',
                select_removable: 'Seleccionar eliminables',
                removable_label: 'eliminables',
                remove_selected: 'Eliminar del contingut',
                url_removing: 'Eliminant...',
                url_removed_ok: 'Referències URL eliminades del contingut.',
                confirm_remove_urls: 'Eliminar les referències URL trencades del contingut? S\'esborrarà l\'etiqueta img o l\'enllaç.',
                url_click_select_remove: 'Fes clic per seleccionar i eliminar',
                url_no_fix_label: 'No s\'ha trobat cap alternativa. Selecciona i elimina la referència del contingut, o restaura el fitxer manualment.',
                posts_scanned: 'Continguts escanejats',
                broken_urls: 'URLs trencades',
                fixable_urls: 'Reparable automàticament',
                used_in: 'Usada a',
                orphan_confirmed: 'Orfe confirmat: no referenciat en cap lloc.',
                not_in_content: 'No trobat a post_content.',
                optimized_ok: 'Optimitzada!',
                optimized_no_replace: 'Optimitzada (sense reemplaçar).',
                converted_bigger: 'Convertida però més gran que l\'original.',
                optimizing_thumbs: 'Optimitzant miniatures...',
                thumbs_done: 'Miniatures processades.',
                reverted_ok: 'Revertida!',
                n_selected: 'seleccionades',
                optimize_done: 'estalviat',
                bulk_done: 'Fet',
                bulk_processing: 'Processant',
                pdf_compress_btn: 'Comprimir',
                pdf_timeout_msg: 'GhostScript ha trigat massa. Comprova el FTP — pot estar comprimit. Recarrega la pàgina.',
                gs_available: 'GhostScript disponible',
                gs_none: 'Cap motor de compressió disponible. Instal·la GhostScript.',
                auto_enabled: 'Auto-optimització ACTIVADA',
                auto_disabled: 'Auto-optimització desactivada',
                auto_desc_enabled: 'Les noves imatges es convertiran automàticament en pujar-les.',
                auto_desc_disabled: 'Activa per optimitzar automàticament.',
                repaired_msg: 'imatges reparades. Recarrega SEO & Noms per veure-les.',
                confirm_clean_30: 'Eliminar entrades de fa més de 30 dies?',
                confirm_clean_all: 'Eliminar TOT l\'historial? Aquesta acció és irreversible.',
                btn_scanning: 'Escanejant...',
                btn_processing: 'Processant...',
                btn_deleting: 'Eliminant...',
                btn_reverting: 'Revertint...',
                revert_rename_mismatch: 'No es pot revertir perquè has canviat el nom del fitxer després d\'optimitzar-lo. El backup encara existeix al servidor però ja no correspon al nom actual (per exemple, has eliminat el sufix -e172…). Solucions: torna a posar el nom original i llavors podràs revertir; elimina aquest backup i torna a optimitzar per generar-ne un de nou; o recupera el fitxer manualment des de la pestanya Imatges òrfenes → Fitxers extra a uploads.',
                btn_repairing: 'Reparant...',
                webp_ok: 'Suportat',
                webp_nok: 'No disponible (GD sense WebP)',
                enter_name: 'Introdueix el nou nom.',
                stat_total: 'Total d\'operacions',
                stat_saved: 'Espai alliberat',
                stat_current_size: 'Mida actual',
                stat_real_format: 'Format real',
                images_deleted: 'imatges eliminades.',
                all_rogue_deleted: 'Fitxers extra seleccionats eliminats!',
                featured: 'Imatge destacada',
                no_alt_text: 'Sense text alternatiu',
                url_fixed_ok: 'URLs reparades correctament.',
                mime_fix_btn: '🔧 Reparar tipus MIME incorrectes',
                mime_fixed: 'adjunts reparats.',
                mime_no_issues: 'Cap discrepància trobada. Tots els adjunts estan correctes.',
                scan_ghosts: 'Escanejar adjunts fantasma',
                no_ghosts: 'No s\'han trobat adjunts fantasma.',
                delete_ghosts: 'Eliminar seleccionats',
                confirm_delete_ghosts: 'Eliminar',
                deleted_msg: 'Eliminats',
                ghost_deleted_ok: 'eliminats correctament.',
                ghost_deleted_none: 'Cap eliminat.',
                errors_label: 'Errades:',
                pdf_engine_warn: '⚠ GhostScript no trobat. S\'està utilitzant Imagick.',
                tab_dashboard: 'Resum',
                dash_title: 'Resum del lloc',
                dash_desc: 'Visió ràpida de la salut de les imatges, motors disponibles i accions pendents.',
                dash_total_images: 'Imatges a la biblioteca',
                dash_missing_alt: 'Alt text absent',
                dash_backups: 'Còpies de seguretat TSO',
                dash_saved: 'Espai estalviat',
                dash_operations: 'operacions del plugin',
                dash_auto_on: 'Auto-optimitzar ACTIVAT',
                dash_auto_off: 'Auto-optimitzar DESACTIVAT',
                dash_auto_label: 'Auto-optimitzar',
                dash_auto_status_on: 'ACTIVAT',
                dash_auto_status_off: 'DESACTIVAT',
                dash_auto_format_sub: 'Format de sortida: %s',
                dash_auto_disabled_hint: 'Desactivat — activa a la pestanya Auto',
                dash_alt_title: 'Imatges sense alt (o alt genèric)',
                dash_alt_desc: 'Suggereix alt des del títol o nom de fitxer. Clica ✎ per editar una fila; Desar (verd) guarda l\'alt. «Omplir alt seleccionades» usa el text editat.',
            dash_alt_save: 'Desar',
            dash_alt_edit: 'Editar alt',
            dash_alt_accept: 'Acceptar alt suggerit',
            dash_alt_cancel: 'Cancel·lar',
                dash_alt_used_only: 'Només usades en contingut',
                dash_alt_used_only_tip: 'Mostra només imatges referenciades en entrades, pàgines, imatges destacades, widgets o camps personalitzats. Oculta pujades no usades de la biblioteca.',
                dash_alt_fill: 'Omplir alt seleccionades',
                dash_alt_fill_tip: 'Escriu l\'alt suggerit (títol si és útil, sinó nom de fitxer humanitzat) a cada imatge seleccionada. Omet les que ja tenen un alt útil.',
                dash_alt_suggested: 'Alt suggerit',
                dash_alt_file_col: 'Fitxer',
                dash_alt_used_in: 'Usada a',
                dash_alt_updated: 'texts alt actualitzats.',
                dash_alt_skipped: 'omeses (ja tenien alt).',
                dash_alt_manual_hint: 'Escriu text alt…',
                dash_engine_gd: 'GD WebP',
                dash_engine_avif: 'GD AVIF',
                dash_engine_gs: 'GhostScript',
                dash_engine_imagick: 'Imagick',
                dash_engine_info_title: 'Motors del servidor',
                dash_engine_gd_desc: 'PHP GD amb lectura/escriptura WebP. Necessari per convertir a WebP i la majoria d\'optimitzacions.',
                dash_engine_avif_desc: 'PHP GD amb lectura/escriptura AVIF. Només cal si tries AVIF com a format de sortida.',
                dash_engine_gs_desc: 'Eina de línia de comandes per comprimir PDFs a la pestanya PDFs.',
                dash_engine_imagick_desc: 'Extensió PHP ImageMagick. Reserva per comprimir PDFs i algunes tasques d\'imatge.',
                dash_engine_available: 'Disponible en aquest servidor.',
                dash_engine_unavailable: 'No disponible en aquest servidor.',
                dash_engine_why_fail: 'Per què no funciona',
                dash_engine_close: 'Tancar',
                dash_go_orphans: 'Trobar òrfenes',
                dash_go_urls: 'Reparar URLs',
                dash_alt_all_ok: 'Totes les imatges tenen un alt útil.',
                dash_queue_title: 'Cua en segon pla',
                dash_queue_desc: 'Les optimitzacions massives s\'executen en segon pla via WP-Cron (5 imatges per lot).',
                dash_queue_cancel: 'Cancel·lar pendents / miniatures',
                dash_queue_empty: 'La cua és buida.',
                dash_queue_done: 'fetes',
                dash_queue_pending: 'pendents',
                dash_queue_processing: 'processant',
            dash_queue_thumbs: 'miniatures pendents',
                dash_queue_errors: 'errors',
                dash_queue_queued: 'En cua per processar en segon pla...',
                dash_queue_queued_n: 'imatges en cua.',
                dash_queue_skipped_n: 'ometudes (ocupades o ja a la cua).',
                dash_backup_title: 'Retenció de còpies de seguretat',
                dash_backup_desc: 'Elimina automàticament les còpies TSO a uploads/tso-image-master/ (0 = desactivat).',
                dash_backup_days: 'Conservar còpies (dies)',
                dash_backup_max: 'Mida màxima total (MB, 0 = il·limitat)',
                dash_backup_purge: 'Purga ara',
                dash_backup_purge_noop: 'La retenció està desactivada (0 dies i 0 MB). Res a netejar.',
                dash_dup_title: 'Imatges duplicades',
                dash_dup_scan: 'Escanejar duplicats',
                dash_dup_none: 'No s\'han trobat grups de duplicats.',
            dash_dup_groups: 'grups de duplicats',
            dash_dup_wasted: 'Espai malgastat',
            dash_dup_explain: 'Cada grup són fitxers idèntics (mateix MD5) registrats més d\'una vegada a la Mediateca. Conserva una entrada i elimina la resta des de la Mediateca de WordPress.',
            dash_dup_rescan_hint: 'Aquestes dades són de l\'últim escaneig. Si acabes d\'editar un article, torna a prémer «Escanejar duplicats» per veure l\'estat actual.',
            dash_dup_group_label: 'Fitxer idèntic',
            dash_dup_entries: 'entrades',
            dash_dup_wasted_in_group: 'malgastats en aquest grup',
            dash_dup_same_name: 'Mateix nom de fitxer però IDs de Mediateca diferents.',
            dash_dup_media_id: 'ID Mediateca',
            dash_dup_upload_path: 'Ubicació',
            dash_dup_uploaded: 'Pujada',
            dash_dup_dimensions: 'Mida',
            dash_dup_refs: 'Referenciada a',
            dash_dup_refs_none: 'No referenciada al contingut (pot ser orfe)',
            dash_dup_refs_more: 'més',
            dash_dup_open_im: 'Obrir a Image Master',
            dash_dup_open_wp: 'Mediateca WP',
            dash_dup_item_title: 'Títol',
            dash_scanned: 'escanejades',
            dash_dup_keep: 'Conserva aquesta (usada al contingut)',
            dash_dup_attached_only: 'Només adjuntada (no es mostra al contingut)',
            dash_dup_delete_ok: 'Es pot eliminar (duplicat no usat)',
            dash_dup_detach: 'Desvincular',
            dash_dup_detach_confirm: 'Desvincular aquest fitxer de l\'entrada a la qual està adjunt a la Biblioteca de mitjans? Això no esborra el fitxer — podràs eliminar-lo després si resulta que no s\'usa enlloc més.',
            dash_dup_detaching: 'Desvinculant...',
            dash_dup_detached_ok: 'Desvinculada.',
            dash_dup_used_direct: 'Usada al contingut',
            dash_dup_used_indirect: 'Només coincidència de nom (aquest ID no és al codi)',
            dash_dup_active_id: 'ID actiu al post',
            dash_dup_open_post: 'Obrir entrada',
            dash_dup_merge: 'Fusionar duplicats',
            dash_dup_merge_hint: 'Conserva els fitxers marcats a sota (en pots marcar més d\'un), reapunta tot el contingut que feia servir la resta i esborra els fitxers redundants.',
            dash_dup_merge_all_kept: 'Tots els fitxers estan marcats per conservar — no hi ha res per fusionar ni esborrar.',
            dash_dup_merge_none_kept: 'Marca almenys un fitxer per conservar abans de fusionar.',
            dash_dup_keep_this: 'Conservar aquest',
            dash_dup_merge_confirm: 'Això reescriurà totes les entrades que fan servir les altres còpies perquè apuntin al fitxer conservat, i després esborrarà els fitxers redundants. No es pot desfer automàticament. Continuar?',
            dash_dup_merging: 'Fusionant...',
            dash_dup_posts_updated: 'entrades actualitzades',
            dash_dup_files_deleted: 'fitxers redundants esborrats',
            dash_dup_kept_review: 'deixats sense tocar (encara referenciats en algun lloc que l\'eina no pot reescriure amb seguretat — revisa’ls a mà)',
            dash_dup_delete: 'Eliminar',
            dash_dup_delete_confirm: 'Eliminar aquest fitxer definitivament? El plugin tornarà a comprovar just abans d\'esborrar que res el referencia. No es pot desfer.',
            dash_dup_deleting: 'Eliminant...',
            dash_dup_deleted_ok: 'Fitxer eliminat.',
            dash_dup_delete_blocked: 'No eliminat: ha aparegut una referència a aquest fitxer des de l\'últim escaneig. Torna a escanejar per veure l\'ús actual.',
            dash_heavy_btn: 'Imatges més pesades',
                auto_skip_kb_label: 'Ometre auto-optimitzar si WebP/AVIF ≤ (KB, 0 = off)',
                auto_fill_alt_label: 'Omplir alt absent en pujar',
                fmt_avif: 'AVIF',
                fmt_png: 'PNG',
                fmt_webp: 'WebP (recomanat)',
                fmt_keep: 'Mantenir format original',
                fmt_keep_current: 'Mantenir format actual',
                per_page_21: '📄 21 / pàgina',
                per_page_35: '📄 35 / pàgina',
                per_page_49: '📄 49 / pàgina',
                per_page_70: '📄 70 / pàgina',
                per_page_105: '📄 105 / pàgina',
                hist_seo_bulk: 'Omplerta en bloc',
                hist_seo_auto: 'En pujar',
                tab_sizes: 'Mides',
                sizes_title: 'Mides òrfenes no registrades',
                sizes_show_backups: 'Veure còpies de seguretat',
                sizes_backups_title: 'Còpies de seguretat desades',
                sizes_backups_desc: 'Es desen dins uploads/tso-image-master/ i es netegen soles segons la retenció configurada al Resum.',
                sizes_backups_loading: 'Carregant...',
                sizes_no_backups: 'No s\'han trobat còpies de seguretat.',
                sizes_backups_count_label: 'còpia(es) de seguretat',
                sizes_backups_col_date: 'Data',
                sizes_backups_col_count: 'Fitxers',
                sizes_backups_batch_tag: 'lot de mida',
                sizes_backups_download: 'Descarregar',
                sizes_backups_delete: 'Eliminar',
                confirm_delete_backup: 'Eliminar permanentment la còpia de seguretat "{name}"? Aquesta acció NO es pot desfer.',
                sizes_desc: 'Agrupa tots els fitxers generats per antics plugins/temes que ja no estan registrats ni en ús actualment a WordPress. Mai toca el fitxer original ni les altres mides.',
                scan_sizes: '📐 Escanejar mides',
                sizes_scanning_msg: 'Escanejant la biblioteca de mitjans...',
                sizes_none_found: 'No s\'han trobat fitxers de mida generats.',
                sizes_all_clean: 'Res per eliminar — totes les mides generades estan en ús o protegides per WordPress.',
                sizes_names_label: 'noms de mida',
                sizes_images_scanned: 'imatges escanejades',
                sizes_col_name: 'Nom de mida',
                sizes_col_dimensions: 'Dimensions',
                sizes_col_count: 'Fitxers',
                sizes_col_space: 'Espai',
                sizes_col_registered: 'Registrada ara?',
                sizes_registered_yes: 'Sí',
                sizes_registered_no: 'No — òrfena',
                sizes_view_files: 'Veure fitxers',
                sizes_delete_all: 'Eliminar totes',
                sizes_delete_all_tip: 'Es desarà automàticament una còpia de seguretat zip de tots els fitxers abans d\'eliminar-los.',
                sizes_back: '← Tornar a la llista de mides',
                sizes_irreversible_note: '⚠️ Eliminar fitxers de mida és <strong>irreversible</strong>. Si no n\'estàs segur, prova primer amb una mostra petita i revisa el lloc abans de continuar amb la resta.',
                sizes_select_sample: 'Seleccionar mostra (3)',
                sizes_detail_title_prefix: 'Mida:',
                sizes_load_more: 'Carregar més',
                sizes_deleted_msg: 'fitxer(s) de mida eliminats',
                sizes_backup_made: 'Còpia de seguretat desada a: {path}',
                sizes_backup_failed: 'No s\'ha pogut fer còpia de seguretat abans d\'eliminar',
                confirm_delete_size_sample: 'Eliminar aquests {n} fitxers de "{size}" com a prova? Comprova que el teu lloc segueix funcionant bé abans d\'eliminar la resta.',
                confirm_delete_size_all: 'Això eliminarà permanentment {n} fitxers de mida "{size}" ({bytes}). Aquesta acció NO es pot desfer. Continuar?',
                action_size_deleted: 'Mida eliminada',
                filter_size_deleted: '📐 Mides eliminades',
                hist_size_deleted_detail: 'Mida',
                sizes_protected_core: '🔒 Protegida (WP)',
                sizes_protected_core_tip: 'Generada pel propi WordPress per a la icona del lloc/favicon, fora del registre normal de mides — no és una resta, conserva-la.'
            }
        };
        [ 'ca', 'es', 'en' ].forEach(function(l) {
            $.extend(I18N_STATIC[l], WP[l]);
        });
    })();

    // ================================================================
    // Theme switcher (auto by sunrise/sunset / day / night)
    // ================================================================
    var THEME_AUTO_TIMER = null;
    var THEME_DAY_MS = 86400000;
    var THEME_J1970 = 2440588;
    var THEME_J2000 = 2451545;
    var THEME_RAD = Math.PI / 180;
    var THEME_E = THEME_RAD * 23.4397;

    // Approximate city coordinates for common WordPress timezones.
    var THEME_TZ_COORDS = {
        'Europe/Madrid': [40.42, -3.70],
        'Europe/Andorra': [42.51, 1.52],
        'Atlantic/Canary': [28.29, -16.63],
        'Europe/London': [51.51, -0.13],
        'Europe/Paris': [48.86, 2.35],
        'Europe/Berlin': [52.52, 13.41],
        'Europe/Rome': [41.90, 12.50],
        'Europe/Lisbon': [38.72, -9.14],
        'Europe/Brussels': [50.85, 4.35],
        'Europe/Amsterdam': [52.37, 4.90],
        'America/Mexico_City': [19.43, -99.13],
        'America/New_York': [40.71, -74.01],
        'America/Chicago': [41.88, -87.63],
        'America/Denver': [39.74, -104.99],
        'America/Los_Angeles': [34.05, -118.24],
        'America/Argentina/Buenos_Aires': [-34.60, -58.38],
        'America/Sao_Paulo': [-23.55, -46.63],
        'America/Bogota': [4.71, -74.07],
        'America/Lima': [-12.05, -77.04],
        'America/Santiago': [-33.45, -70.67],
        'America/Caracas': [10.48, -66.90],
        'Asia/Tokyo': [35.68, 139.69],
        'Australia/Sydney': [-33.87, 151.21],
        'UTC': [0, 0]
    };

    function readThemePreference() {
        var theme = localStorage.getItem('tsoimma_ui_theme');
        if (theme === 'day' || theme === 'night' || theme === 'auto') {
            return theme;
        }
        return 'auto';
    }

    function getThemeCoords() {
        if (typeof TSOIMMA !== 'undefined') {
            var wpLat = parseFloat(TSOIMMA.lat);
            var wpLng = parseFloat(TSOIMMA.lng);
            if (!isNaN(wpLat) && !isNaN(wpLng)) {
                return { lat: wpLat, lng: wpLng, source: 'wordpress' };
            }
        }

        try {
            var raw = localStorage.getItem('tsoimma_theme_coords');
            if (raw) {
                var parsed = JSON.parse(raw);
                if (parsed && typeof parsed.lat === 'number' && typeof parsed.lng === 'number') {
                    return parsed;
                }
            }
        } catch (e) { /* ignore */ }

        var tz = '';
        if (typeof TSOIMMA !== 'undefined' && TSOIMMA.timezone) {
            tz = String(TSOIMMA.timezone);
        }
        if (!tz && typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
            try {
                tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            } catch (e2) { /* ignore */ }
        }

        if (tz && THEME_TZ_COORDS[tz]) {
            return { lat: THEME_TZ_COORDS[tz][0], lng: THEME_TZ_COORDS[tz][1], source: 'timezone' };
        }

        // Default: Iberian Peninsula.
        return { lat: 41.39, lng: 2.17, source: 'default' };
    }

    function themeToJulian(date) {
        return date.valueOf() / THEME_DAY_MS - 0.5 + THEME_J1970;
    }

    function themeFromJulian(j) {
        return new Date((j + 0.5 - THEME_J1970) * THEME_DAY_MS);
    }

    function themeToDays(date) {
        return themeToJulian(date) - THEME_J2000;
    }

    function themeRightAscension(l, b) {
        return Math.atan2(Math.sin(l) * Math.cos(THEME_E) - Math.tan(b) * Math.sin(THEME_E), Math.cos(l));
    }

    function themeDeclination(l, b) {
        return Math.asin(Math.sin(b) * Math.cos(THEME_E) + Math.cos(b) * Math.sin(THEME_E) * Math.sin(l));
    }

    function themeSolarMeanAnomaly(d) {
        return THEME_RAD * (357.5291 + 0.98560028 * d);
    }

    function themeEclipticLongitude(M) {
        var C = THEME_RAD * (1.9148 * Math.sin(M) + 0.02 * Math.sin(2 * M) + 0.0003 * Math.sin(3 * M));
        var P = THEME_RAD * 102.9372;
        return M + C + P + Math.PI;
    }

    function themeSunCoords(d) {
        var M = themeSolarMeanAnomaly(d);
        var L = themeEclipticLongitude(M);
        return {
            dec: themeDeclination(L, 0),
            ra: themeRightAscension(L, 0)
        };
    }

    function themeJulianCycle(d, lw) {
        return Math.round(d - 0.0009 - lw / (2 * Math.PI));
    }

    function themeApproxTransit(Ht, lw, n) {
        return 0.0009 + (Ht + lw) / (2 * Math.PI) + n;
    }

    function themeSolarTransitJ(ds, M, L) {
        return THEME_J2000 + ds + 0.0053 * Math.sin(M) - 0.0069 * Math.sin(2 * L);
    }

    function themeHourAngle(h, phi, d) {
        return Math.acos((Math.sin(h) - Math.sin(phi) * Math.sin(d)) / (Math.cos(phi) * Math.cos(d)));
    }

    function themeGetSetJ(h, lw, phi, dec, n, M, L) {
        var w = themeHourAngle(h, phi, dec);
        var a = themeApproxTransit(w, lw, n);
        return themeSolarTransitJ(a, M, L);
    }

    /**
     * Official sunrise/sunset (-0.833°) for a local calendar day.
     *
     * @param {Date} date
     * @param {number} lat
     * @param {number} lng
     * @return {{sunrise: Date, sunset: Date}|null}
     */
    function getSunTimes(date, lat, lng) {
        try {
            var lw = THEME_RAD * -lng;
            var phi = THEME_RAD * lat;
            var d = themeToDays(date);
            var n = themeJulianCycle(d, lw);
            var ds = themeApproxTransit(0, lw, n);
            var M = themeSolarMeanAnomaly(ds);
            var L = themeEclipticLongitude(M);
            var dec = themeSunCoords(ds).dec;
            var Jnoon = themeSolarTransitJ(ds, M, L);
            var Jset = themeGetSetJ(-0.833 * THEME_RAD, lw, phi, dec, n, M, L);
            var Jrise = Jnoon - (Jset - Jnoon);
            var sunrise = themeFromJulian(Jrise);
            var sunset = themeFromJulian(Jset);
            if (isNaN(sunrise.getTime()) || isNaN(sunset.getTime())) {
                return null;
            }
            return { sunrise: sunrise, sunset: sunset };
        } catch (e) {
            return null;
        }
    }

    function formatThemeClock(date) {
        try {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            var h = date.getHours();
            var m = date.getMinutes();
            return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
        }
    }

    function themeFromSolar() {
        var coords = getThemeCoords();
        var now = new Date();
        var times = getSunTimes(now, coords.lat, coords.lng);
        if (!times) {
            // Polar / calculation edge fallback.
            var hour = now.getHours();
            return (hour >= 7 && hour < 20) ? 'day' : 'night';
        }
        return (now >= times.sunrise && now < times.sunset) ? 'day' : 'night';
    }

    function resolveTheme(preference) {
        if (preference === 'day' || preference === 'night') {
            return preference;
        }
        return themeFromSolar();
    }

    // Mirrors the resolved theme (day/night) into a cookie so the PHP-side
    // render can pick the right wrapper background and initial data-theme
    // on the very first paint, before this script has a chance to run —
    // this is what stops a black flash in the Day theme on page load (F5).
    function setThemeCookie(resolved) {
        try {
            document.cookie = 'tsoimma_theme_resolved=' + resolved + '; path=/; max-age=31536000; SameSite=Lax';
        } catch (e) {}
    }

    function applyTheme(preference) {
        if (preference !== 'day' && preference !== 'night' && preference !== 'auto') {
            preference = 'auto';
        }
        localStorage.setItem('tsoimma_ui_theme', preference);

        var resolved = resolveTheme(preference);
        $('#imp-app').attr('data-theme', resolved);
        $('#imp-app').attr('data-theme-pref', preference);
        setThemeCookie(resolved);

        var $btn = $('#imp-theme-toggle');
        var labelKey = 'theme_' + preference;
        var labelFallback = preference === 'auto' ? 'Mode auto' : (preference === 'day' ? 'Mode dia' : 'Mode nit');
        var icon = preference === 'auto' ? '🌓' : (resolved === 'night' ? '🌙' : '☀️');
        var label = uiText(labelKey, labelFallback);
        var title = label;
        if (preference === 'auto') {
            title = label + ' — ' + uiText('theme_auto_hint', 'Segons alba i ocàs (canvia amb l\'estació)');
            var coords = getThemeCoords();
            var times = getSunTimes(new Date(), coords.lat, coords.lng);
            if (times) {
                title += ' · ' + formatThemeClock(times.sunrise) + '–' + formatThemeClock(times.sunset);
            }
        }

        $btn.attr('aria-pressed', preference === 'auto' ? 'mixed' : (resolved === 'night' ? 'true' : 'false'));
        $btn.attr('title', title);
        $btn.find('.imp-theme-icon').text(icon);
        $btn.find('.imp-theme-label').attr('data-i18n', labelKey).text(label);

        if (THEME_AUTO_TIMER) {
            clearInterval(THEME_AUTO_TIMER);
            THEME_AUTO_TIMER = null;
        }
        if (preference === 'auto') {
            THEME_AUTO_TIMER = setInterval(function() {
                if (readThemePreference() !== 'auto') {
                    return;
                }
                var nextResolved = themeFromSolar();
                if ($('#imp-app').attr('data-theme') !== nextResolved) {
                    applyTheme('auto');
                }
            }, 60000);
        }
    }

    function nextThemePreference(current) {
        if (current === 'auto') {
            return 'day';
        }
        if (current === 'day') {
            return 'night';
        }
        return 'auto';
    }

    function initThemeSwitcher() {
        applyTheme(readThemePreference());
        $(document).on('click', '#imp-theme-toggle', function() {
            applyTheme(nextThemePreference(readThemePreference()));
        });
    }

    // Apply stored/auto theme ASAP when the app root is already in the DOM
    // (this script is enqueued in the footer, so #imp-app already exists),
    // then reveal the page — it starts hidden via CSS (.imp-wrap has
    // opacity:0 until .imp-theme-ready is added) precisely so this runs
    // before anything becomes visible, instead of showing the hardcoded
    // "night" default first and flashing to the real theme afterwards.
    (function() {
        var appEl = document.getElementById('imp-app');
        if (!appEl) {
            return;
        }
        var resolvedNow = resolveTheme(readThemePreference());
        appEl.setAttribute('data-theme', resolvedNow);
        appEl.classList.add('imp-theme-ready');
        setThemeCookie(resolvedNow);
    })();

    function initLanguageSwitcher() {
        captureCatalanOriginals();
        var defaultLang = detectDefaultLanguage();
        var savedLang = localStorage.getItem('tsoimma_ui_lang');
        var lang = savedLang || defaultLang;
        setLanguage(lang);

        $(document).on('click', '.imp-lang-btn', function() {
            setLanguage($(this).data('lang'));
        });
    }

    function detectDefaultLanguage() {
        var wpLang = document.documentElement.getAttribute('lang') || '';
        wpLang = wpLang.toLowerCase();
        if (wpLang.indexOf('ca') === 0) return 'ca';
        if (wpLang.indexOf('es') === 0) return 'es';
        return 'en';
    }

    function setLanguage(lang) {
        if (['ca', 'es', 'en'].indexOf(lang) === -1) lang = 'ca';
        localStorage.setItem('tsoimma_ui_lang', lang);
        syncRuntimeStrings(lang);
        $('.imp-lang-btn').removeClass('active');
        $('.imp-lang-btn[data-lang="' + lang + '"]').addClass('active');
        applyTranslations(lang);
        refreshDynamicI18nBits();
        refreshImpCustomSelects();
        applyTheme(readThemePreference());
    }

    function uiText(key, fallback) {
        var lang = localStorage.getItem('tsoimma_ui_lang') || detectDefaultLanguage();
        var dict = I18N_STATIC[lang] || {};
        if (dict[key]) return dict[key];
        if (lang === 'ca') return L[key] || fallback || ((I18N_STATIC.en && I18N_STATIC.en[key]) || '');
        return (I18N_STATIC.en && I18N_STATIC.en[key]) || L[key] || fallback;
    }

    function syncRuntimeStrings(lang) {
        var dict = I18N_STATIC[lang] || {};
        var key;
        for (key in L) {
            if (Object.prototype.hasOwnProperty.call(L, key)) delete L[key];
        }
        // BASE_L follows WordPress admin locale; dict (plugin UI language) must override every key we define.
        $.extend(L, BASE_L, dict);
    }

    function applyTranslations(lang) {
        var dict = I18N_STATIC[lang] || {};
        $('[data-i18n]').each(function() {
            var key = $(this).data('i18n');
            // Prefer I18N_STATIC over captured HTML so new keys translated in JS win
            // even if PHP markup still has English fallbacks.
            var text = (lang === 'ca')
                ? (dict[key] || CA_ORIGINAL.text[key] || L[key])
                : (dict[key] || L[key]);
            if (text) $(this).html(text);
        });
        $('[data-i18n-placeholder]').each(function() {
            var key = $(this).data('i18n-placeholder');
            var ph = (lang === 'ca')
                ? (dict[key] || CA_ORIGINAL.placeholder[key] || L[key])
                : (dict[key] || L[key]);
            if (ph) $(this).attr('placeholder', ph);
        });
        $('[data-i18n-html]').each(function() {
            var key = $(this).data('i18n-html');
            var html = (lang === 'ca')
                ? (dict[key] || CA_ORIGINAL.html[key] || L[key])
                : (dict[key] || L[key]);
            if (html) $(this).html(html);
        });
    }

    function captureCatalanOriginals() {
        $('[data-i18n]').each(function() {
            var key = $(this).data('i18n');
            if (!CA_ORIGINAL.text[key]) CA_ORIGINAL.text[key] = $(this).html();
        });
        $('[data-i18n-placeholder]').each(function() {
            var key = $(this).data('i18n-placeholder');
            if (!CA_ORIGINAL.placeholder[key]) CA_ORIGINAL.placeholder[key] = $(this).attr('placeholder') || '';
        });
        $('[data-i18n-html]').each(function() {
            var key = $(this).data('i18n-html');
            if (!CA_ORIGINAL.html[key]) CA_ORIGINAL.html[key] = $(this).html();
        });
    }

    function refreshDynamicI18nBits() {
        // Buttons whose text is mutated after async actions.
        $('#imp-fix-orphan-meta').text(uiText('repair_paths', '🔧 Repair broken paths'));
        $('#imp-fix-mime-mismatch').text(uiText('mime_fix_btn', '🔧 Fix incorrect mime types'));
        $('#imp-scan-ghosts').text('🔍 ' + uiText('scan_ghosts', 'Scan ghost attachments'));
        $('#imp-delete-ghosts').text('🗑 ' + uiText('delete_ghosts', 'Delete selected'));
        $('#imp-scan-rogue').text(uiText('scan_rogue', '🔍 Scan extra upload files'));
        $('#imp-save-rename').text(uiText('rename_btn', '✏️ Rename file'));
        $('#imp-alt-grid .imp-alt-manual-hint').text(uiText('dash_alt_manual_hint', 'Write alt text…'));
        $('#imp-alt-grid .imp-alt-suggest-input').each(function() {
            var $row = $(this).closest('.imp-alt-row');
            if ($row.hasClass('is-manual-alt') && !$.trim($(this).val())) {
                $(this).attr('placeholder', uiText('dash_alt_manual_hint', 'Write alt text…'));
            }
        });

        // Re-render result messages if they are visible and previously stored.
        refreshStoredResult('#imp-fix-orphan-result');
        refreshStoredResult('#imp-fix-mime-result');
        refreshStoredResult('#imp-ghost-scan-result');
        refreshStoredResult('#imp-ghost-delete-result');
        if (typeof refreshUrlFixerUi === 'function') refreshUrlFixerUi();
        if (typeof window.tsoimmaRefreshRogueUi === 'function') window.tsoimmaRefreshRogueUi();
        // Re-render PDF rows/badges in the selected UI language.
        if ($('#tab-pdf').is(':visible')) loadPdfs();
        // Re-render history tables/stats in current UI language.
        if ($('#tab-history').is(':visible')) {
            loadHistoryStats('#imp-history-stats');
            loadHistory();
        }
    }

    function refreshStoredResult(selector) {
        var $el = $(selector);
        var kind = $el.data('kind');
        if (!kind) return;
        var count = parseInt($el.data('count') || 0, 10);

        if (kind === 'repaired') {
            $el.text('✓ ' + count + ' ' + uiText('repaired_msg', 'images repaired.'));
        } else if (kind === 'mime_fixed') {
            $el.text('✓ ' + count + ' ' + uiText('mime_fixed', 'attachments repaired.'));
        } else if (kind === 'mime_no_issues') {
            $el.text('✓ ' + uiText('mime_no_issues', 'No issues found. All attachments are correct.'));
        } else if (kind === 'ghost_none') {
            $el.text('✓ ' + uiText('no_ghosts', 'No ghost attachments found.'));
        } else if (kind === 'ghost_found') {
            $el.text(count + ' ' + (count > 1 ? uiText('ghost_found_plural', 'ghost attachments found.') : uiText('ghost_found_singular', 'ghost attachment found.')));
        } else if (kind === 'ghost_deleted') {
            $el.text('✓ ' + count + ' ' + uiText('ghost_deleted_ok', 'deleted successfully.'));
        }
    }

    // ================================================================
    // Hover Preview
    // ================================================================
    function initHoverPreview() {
        if (!$('#imp-img-hover-preview').length) {
            // Appended inside .imp-wrap (not body) so it inherits the
            // --imp-* theme variables; those are scoped to .imp-wrap and
            // don't reach a sibling under <body>, which is why this popup
            // rendered with a transparent background in both themes.
            $('.imp-wrap').first().append('<div id="imp-img-hover-preview"><img src="" alt=""></div>');
        }
        var $preview = $('#imp-img-hover-preview');
        var $pImg  = $preview.find('img');
        var MARGIN = 16;
        var hoverSelector = '#imp-modal-img, .imp-alt-row-thumb, .imp-history-table .imp-thumb-sm';

        function positionPreview(e) {
            var pw = $preview.outerWidth()  || 300;
            var ph = $preview.outerHeight() || 300;
            var ww = $(window).width();
            var wh = $(window).height();
            var x  = e.clientX + MARGIN;
            var y  = e.clientY - ph / 2;
            if (x + pw > ww - MARGIN) x = e.clientX - pw - MARGIN;
            if (y < MARGIN) y = MARGIN;
            if (y + ph > wh - MARGIN) y = wh - ph - MARGIN;
            $preview.css({ left: Math.max(0, x) + 'px', top: Math.max(0, y) + 'px' });
        }

        function hidePreview() {
            hideImgHoverPreview();
        }

        function showPreviewFromTarget($target, e) {
            var fullUrl = $target.attr('data-full-url') || $target.attr('src') || '';
            if (!fullUrl) {
                return;
            }
            var loadId = ++hoverPreviewLoadId;
            var ts      = Date.now();
            var sep     = fullUrl.indexOf('?') === -1 ? '?' : '&';
            var noCache = fullUrl + sep + '_hov=' + ts;
            $preview.removeClass('visible').hide();
            var tmpImg = new Image();
            tmpImg.onload = function() {
                if (loadId !== hoverPreviewLoadId) {
                    return;
                }
                $pImg.attr('src', noCache);
                positionPreview(e);
                $preview.show();
                requestAnimationFrame(function() { $preview.addClass('visible'); });
            };
            tmpImg.onerror = function() {
                if (loadId !== hoverPreviewLoadId) {
                    return;
                }
                hideImgHoverPreview();
            };
            tmpImg.src = noCache;
        }

        $(document).on('mouseenter', hoverSelector, function(e) {
            showPreviewFromTarget($(this), e);
        });

        $(document).on('mouseleave', hoverSelector + ', #imp-alt-grid', function() {
            hidePreview();
        });
        $(document).on('mousemove', hoverSelector, function(e) {
            if ($preview.is(':visible')) {
                positionPreview(e);
            }
        });
    }

    // ================================================================
    // WebP check
    // ================================================================
    function checkWebP() {
        if (TSOIMMA.webp_ok === '1') {
            $('#imp-webp-status').html(uiText('webp_label', 'WebP') + ': <span class="ok">✓ ' + uiText('webp_ok', 'Supported') + '</span>');
        } else {
            $('#imp-webp-status').html(uiText('webp_label', 'WebP') + ': <span class="nok">✗ ' + uiText('webp_nok', 'Not available') + '</span>');
            $('#imp-format, #imp-modal-format').find('option[value="webp"]').prop('disabled', true);
            refreshImpCustomSelects('#imp-format, #imp-modal-format');
        }
    }

    // Custom dropdowns (PHP markup + JS bind; native menus unreadable on Windows).
    function closeImpCustomSelects() {
        $('.imp-csel.is-open').removeClass('is-open')
            .find('.imp-csel-trigger').attr('aria-expanded', 'false');
    }

    function refreshImpCustomSelects(context) {
        var $wraps = context ? $(context).closest('.imp-csel').add($(context).filter('.imp-csel')) : $('.imp-csel');
        if (context && !$(context).closest('.imp-csel').length && $(context).hasClass('imp-csel')) {
            $wraps = $(context);
        }
        $wraps.each(function() {
            var $w = $(this);
            var api = $w.data('impCselApi');
            if (api) {
                api.rebuild();
                api.sync();
            } else {
                bindImpCustomSelect($w);
            }
        });
    }

    function initImpCustomSelects() {
        $('.imp-csel').each(function() {
            bindImpCustomSelect($(this));
        });
        $(document).on('click.impCsel', function() {
            closeImpCustomSelects();
        });
        $(document).on('keydown.impCsel', function(e) {
            if (e.key === 'Escape') {
                closeImpCustomSelects();
            }
        });
    }

    function bindImpCustomSelect($wrap) {
        if ($wrap.data('impCselBound')) {
            return;
        }
        var $select = $wrap.find('select.imp-csel-native');
        var $label  = $wrap.find('.imp-csel-label');
        var $list   = $wrap.find('.imp-csel-list');

        function rebuildList() {
            $list.empty();
            $select.find('option').each(function() {
                var $opt = $(this);
                var $li  = $('<li role="option" tabindex="-1"></li>');
                $li.text($opt.text()).attr('data-value', $opt.val());
                if ($opt.prop('disabled')) {
                    $li.addClass('is-disabled');
                }
                if ($opt.prop('selected')) {
                    $li.addClass('is-selected');
                }
                $list.append($li);
            });
        }

        function syncFromSelect() {
            $label.text($select.find('option:selected').first().text());
            $list.find('[role="option"]').removeClass('is-selected');
            $list.find('[role="option"]').each(function() {
                if (String($(this).attr('data-value')) === String($select.val())) {
                    $(this).addClass('is-selected');
                }
            });
        }

        $wrap.find('.imp-csel-trigger').on('click.impCsel', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var wasOpen = $wrap.hasClass('is-open');
            closeImpCustomSelects();
            if (!wasOpen) {
                $wrap.addClass('is-open');
                $(this).attr('aria-expanded', 'true');
            }
        });

        $list.on('click.impCsel', '[role="option"]:not(.is-disabled)', function(e) {
            e.stopPropagation();
            $select.val($(this).attr('data-value')).trigger('change');
            $wrap.removeClass('is-open');
            syncFromSelect();
        });

        $select.on('change.impCsel', syncFromSelect);

        $wrap.data('impCselBound', 1);
        $wrap.data('impCselApi', { rebuild: rebuildList, sync: syncFromSelect });

        rebuildList();
        syncFromSelect();
    }

    // ================================================================
    // Dashboard
    // ================================================================
    function dashboardAltCacheKey() {
        return String(state.dashboard.altPage) + ':' + ($('#imp-alt-used-only').is(':checked') ? '1' : '0');
    }

    function markDashboardDirty() {
        state.dashboard.dirty = true;
    }

    function abortDashboardRequests() {
        if (state.dashboard.xhrOverview) {
            state.dashboard.xhrOverview.abort();
            state.dashboard.xhrOverview = null;
        }
        if (state.dashboard.xhrAlt) {
            state.dashboard.xhrAlt.abort();
            state.dashboard.xhrAlt = null;
        }
    }

    function refreshDashboardTab(force) {
        var needOverview = !!force || !state.dashboard.overviewLoaded || state.dashboard.dirty;
        var altKey = dashboardAltCacheKey();
        var needAlt = !!force || state.dashboard.dirty || !state.dashboard.altListLoaded || state.dashboard.altListCacheKey !== altKey;

        if (!needOverview && !needAlt) {
            return;
        }

        hideImgHoverPreview();
        abortDashboardRequests();
        state.dashboard.dirty = false;

        if (needOverview) {
            loadDashboardOverview(function() {
                if (needAlt) {
                    loadMissingAlt();
                }
            });
            return;
        }
        if (needAlt) {
            loadMissingAlt();
        }
    }

    function invalidateDashboard() {
        markDashboardDirty();
        if ($('#tab-dashboard').hasClass('active')) {
            refreshDashboardTab(false);
        }
    }

    function initDashboard() {
        setTimeout(function() {
            if ($('#tab-dashboard').hasClass('active')) {
                refreshDashboardTab(true);
            }
        }, 120);

        $(document).on('click', '.imp-tab[data-tab="dashboard"]', function() {
            refreshDashboardTab(false);
        });

        $('#imp-alt-used-only').on('change', function() {
            state.dashboard.altPage = 1;
            state.dashboard.altSelected.clear();
            loadMissingAlt();
        });

        $('#imp-alt-select-all').on('click', function() {
            $('#imp-alt-grid .imp-alt-check').prop('checked', true).each(function() {
                var id = parseInt($(this).data('id'), 10);
                if (!id) {
                    return;
                }
                state.dashboard.altSelected.add(id);
                rememberAltDraft(id, getAltDraftFromRow(id));
            });
        });

        $('#imp-alt-deselect').on('click', function() {
            $('#imp-alt-grid .imp-alt-check').prop('checked', false);
            state.dashboard.altSelected.clear();
        });

        $(document).on('change', '.imp-alt-check', function() {
            var id = parseInt($(this).data('id'), 10);
            if ($(this).is(':checked')) state.dashboard.altSelected.add(id);
            else state.dashboard.altSelected.delete(id);
        });

        initAltSuggestEditors();

        function runBulkAltFillSelected(ids, alts, $btn, $res) {
            var batchSize = 20;
            var index = 0;
            var totalUpdated = 0;
            var totalSkipped = 0;
            var errors = [];

            function finish() {
                $btn.prop('disabled', false).text(uiText('dash_alt_fill', 'Fill alt for selected'));
                var msg = '\u2713 ' + totalUpdated + ' ' + uiText('dash_alt_updated', 'alt texts updated.');
                if (totalSkipped) {
                    msg += ' ' + totalSkipped + ' ' + uiText('dash_alt_skipped', 'skipped (already had alt).');
                }
                if (errors.length) {
                    msg += ' \u00b7 ' + errors.slice(0, 2).join(' ');
                }
                $res.show().css('color', errors.length ? 'var(--imp-warn)' : 'var(--imp-success)').text(msg);
                state.dashboard.altSelected.clear();
                state.dashboard.altListLoaded = false;
                state.dashboard.altListCacheKey = '';
                state.dashboard.overviewLoaded = false;
                state.dashboard.dirty = true;
                if ($('#tab-dashboard').hasClass('active')) {
                    refreshDashboardTab(false);
                }
            }

            function nextBatch() {
                if (index >= ids.length) {
                    finish();
                    return;
                }
                var chunk = ids.slice(index, index + batchSize);
                index += batchSize;
                var chunkAlts = {};
                chunk.forEach(function(id) {
                    chunkAlts[id] = alts[id] || '';
                });
                var isLast = index >= ids.length;
                ajax('tsoimma_bulk_fill_alt', {
                    ids: chunk,
                    source: 'suggested',
                    alts: chunkAlts,
                    recount: isLast ? 1 : 0
                }, function(data) {
                    totalUpdated += data.updated || 0;
                    totalSkipped += data.skipped || 0;
                    if (data.errors && data.errors.length) {
                        errors = errors.concat(data.errors);
                    }
                    if (isLast && typeof data.missing_alt === 'number') {
                        setDashboardStatValue('missing_alt', data.missing_alt);
                    }
                    setTimeout(nextBatch, 30);
                }, function(err) {
                    $btn.prop('disabled', false).text(uiText('dash_alt_fill', 'Fill alt for selected'));
                    $res.show().css('color', 'var(--imp-danger)').text(err);
                });
            }

            nextBatch();
        }

        $('#imp-alt-bulk-fill').on('click', function() {
            if (!state.dashboard.altSelected.size) {
                alert(uiText('no_selection', 'Select at least one image.'));
                return;
            }
            hideImgHoverPreview();
            var $btn = $(this);
            var $res = $('#imp-alt-bulk-result');
            var ids = Array.from(state.dashboard.altSelected);
            var alts = {};
            ids.forEach(function(id) {
                var draft = getAltDraftFromRow(id);
                if (draft) {
                    alts[id] = draft;
                }
            });
            $btn.prop('disabled', true).text(uiText('processing', 'Processing...'));
            runBulkAltFillSelected(ids, alts, $btn, $res);
        });

        $(document).on('click', '.imp-dash-jump', function(e) {
            e.preventDefault();
            var tab = $(this).data('jump-tab');
            if (!tab) return;
            $('.imp-tab[data-tab="' + tab + '"]').trigger('click');
            if (tab === 'optimize') {
                state.opt.page = 1;
                state.opt.search = '';
                loadOptImages();
            }
        });

        $('#imp-queue-cancel').on('click', function() {
            ajax('tsoimma_cancel_queue', {}, function(data) {
                renderQueueStatus(data);
            });
        });

        $('#imp-save-backup-retention').on('click', function() {
            ajax('tsoimma_save_backup_retention', {
                days: $('#imp-backup-days').val(),
                max_mb: $('#imp-backup-max-mb').val()
            }, function() {
                $('#imp-backup-retention-msg').show().css('color', 'var(--imp-success)').text(uiText('save_ok', 'Saved!'));
            });
        });

        $('#imp-purge-backups-now').on('click', function() {
            ajax('tsoimma_purge_backups_now', {}, function(data) {
                var msg;
                if (data.noop) {
                    msg = uiText('dash_backup_purge_noop', 'Retention is disabled (0 days and 0 MB). Nothing to purge.');
                } else {
                    msg = '✓ ' + (data.deleted || 0) + ' deleted, ' + (data.freed_h || '0 B') + ' freed.';
                }
                $('#imp-backup-retention-msg').show().css('color', 'var(--imp-success)').text(msg);
                invalidateDashboard();
            });
        });

        $('#imp-scan-duplicates').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#imp-duplicates-list').empty();
            scanDuplicatesBatch(0, true);
        });

        $(document).on('click', '.imp-dup-open-im', function(e) {
            e.preventDefault();
            var id = parseInt($(this).data('id'), 10);
            if (!id) return;
            openModal(id, 'optimize');
        });

        $(document).on('click', '.imp-dup-detach-btn', function() {
            var $btn = $(this);
            var id = parseInt($btn.data('id'), 10);
            if (!id) {
                return;
            }
            if (!window.confirm(uiText('dash_dup_detach_confirm', 'Unlink this file from the post it\'s attached to in the Media Library? This does not delete the file — you\'ll be able to delete it afterwards if it turns out to be unused everywhere else.'))) {
                return;
            }

            var originalLabel = $btn.text();
            $btn.prop('disabled', true).text(uiText('dash_dup_detaching', 'Unlinking...'));

            ajax('tsoimma_dup_detach', { attachment_id: id }, function(data) {
                var updated = data.item || {};
                var $item = $btn.closest('.imp-dup-item');
                $item.find('.imp-dup-usedin-wrap').html(renderDuplicateUsedIn(updated));
                $item.find('.imp-dup-cond-actions').html(renderDuplicateCondActions(updated));
                $('#imp-duplicates-result').prepend('<div class="imp-dup-merge-result">✓ ' + escHtml(uiText('dash_dup_detached_ok', 'Unlinked.')) + '</div>');
            }, function(err) {
                $btn.prop('disabled', false).text(originalLabel);
                window.alert(err);
            });
        });

        $(document).on('click', '.imp-dup-delete-btn', function() {
            var $btn = $(this);
            var id = parseInt($btn.data('id'), 10);
            if (!id) {
                return;
            }
            if (!window.confirm(uiText('dash_dup_delete_confirm', 'Delete this file permanently? The plugin will double-check right before deleting that nothing references it. This cannot be undone.'))) {
                return;
            }

            var originalLabel = $btn.text();
            $btn.prop('disabled', true).text(uiText('dash_dup_deleting', 'Deleting...'));

            ajax('tsoimma_dup_delete', { attachment_id: id }, function(data) {
                if (data.deleted) {
                    $('#imp-duplicates-result').prepend('<div class="imp-dup-merge-result">✓ ' + escHtml(uiText('dash_dup_deleted_ok', 'File deleted.')) + '</div>');
                    // Remove just this item in place instead of a full
                    // rescan — rebuilding the whole list (and briefly
                    // showing the "Scanning..." placeholder) shrinks the
                    // page and jumps the scroll position back to the top,
                    // which is disruptive when deleting several duplicates
                    // one after another.
                    var $item  = $btn.closest('.imp-dup-item');
                    var $group = $item.closest('.imp-dup-group');
                    $item.fadeOut(200, function() {
                        $item.remove();
                        var remaining = $group.find('.imp-dup-item').length;
                        if (remaining === 0) {
                            // Nothing left in this group at all.
                            $group.fadeOut(200, function() { $group.remove(); });
                        } else if (remaining === 1) {
                            // Only one copy left — it's no longer a "duplicate"
                            // (nothing to merge it with), but it's still a real,
                            // possibly-unused file that may still need
                            // deleting/unlinking. Removing the whole group card
                            // here used to hide that last item along with its
                            // Delete/Unlink buttons, leaving it stuck until the
                            // next full rescan. Keep it visible and just drop
                            // the now-meaningless merge controls.
                            $group.find('.imp-dup-merge-row').remove();
                            $group.find('.imp-dup-keep-radio').remove();
                            $group.find('.imp-dup-group-entries-count').text(remaining);
                        } else {
                            $group.find('.imp-dup-group-entries-count').text(remaining);
                        }
                    });
                } else {
                    $btn.prop('disabled', false).text(originalLabel);
                    var msg = uiText('dash_dup_delete_blocked', 'Not deleted: a reference to this file appeared since the last scan. Rescan to see current usage.');
                    $('#imp-duplicates-result').prepend('<div class="imp-error">' + escHtml(msg) + '</div>');
                }
            }, function(err) {
                $btn.prop('disabled', false).text(originalLabel);
                window.alert(err);
            });
        });

        $(document).on('change', '.imp-dup-keep-check', function() {
            updateDupMergeState($(this).closest('.imp-dup-group'));
        });

        $(document).on('click', '.imp-dup-merge-btn', function() {
            var $btn = $(this);
            var $group = $btn.closest('.imp-dup-group');
            var groupIndex = $group.data('group-index');
            // More than one item can be marked "Keep this one" now — e.g. two
            // identical images deliberately kept because each is genuinely
            // used in a different post. Every checked item is left untouched;
            // only the unchecked ones become merge/delete candidates. The
            // first checked item is still sent as the single "keep_id" the
            // backend repoints content to — it only matters for an unchecked
            // item that has real content to rewrite, and any checked item's
            // file is an identical byte-for-byte copy, so either works.
            var keepIds = $group.find('input[name="imp-dup-keep-' + groupIndex + '"]:checked').map(function() {
                return parseInt($(this).val(), 10);
            }).get().filter(function(v) { return !!v; });
            var keepId = keepIds[0];
            var allIds = String($group.data('item-ids') || '').split(',').map(function(v) { return parseInt(v, 10); }).filter(function(v) { return !!v; });
            var deleteIds = allIds.filter(function(id) { return keepIds.indexOf(id) === -1; });

            // The button is disabled (see updateDupMergeState) whenever this
            // would happen, but keep an explicit message here too rather
            // than silently doing nothing if it's ever reached anyway.
            if (!keepId) {
                window.alert(uiText('dash_dup_merge_none_kept', 'Check at least one file to keep before merging.'));
                return;
            }
            if (!deleteIds.length) {
                window.alert(uiText('dash_dup_merge_all_kept', 'Every file is marked to keep — there is nothing left to merge or delete.'));
                return;
            }
            if (!window.confirm(uiText('dash_dup_merge_confirm', 'This rewrites every post/page that uses the other copies so they point at the kept file instead, then deletes the now-redundant files. This cannot be undone automatically. Continue?'))) {
                return;
            }

            var originalLabel = $btn.text();
            $btn.prop('disabled', true).text(uiText('dash_dup_merging', 'Merging...'));

            ajax('tsoimma_dup_merge', { keep_id: keepId, delete_ids: deleteIds }, function(data) {
                var updatedCount = Object.keys(data.updated_posts || {}).length;
                var deletedCount = (data.deleted_ids || []).length;
                var skippedCount = Object.keys(data.skipped || {}).length;
                var msg = '✓ ' + updatedCount + ' ' + uiText('dash_dup_posts_updated', 'posts/pages updated') +
                    ' · ' + deletedCount + ' ' + uiText('dash_dup_files_deleted', 'redundant files deleted');
                if (skippedCount) {
                    msg += ' · ' + skippedCount + ' ' + uiText('dash_dup_kept_review', 'left untouched (still referenced somewhere the tool could not safely rewrite — review manually)');
                }
                $('#imp-duplicates-result').prepend('<div class="imp-dup-merge-result">' + escHtml(msg) + '</div>');
                scanDuplicatesBatch(0, true);
            }, function(err) {
                $btn.prop('disabled', false).text(originalLabel);
                window.alert(err);
            }, 180000);
        });

        loadBackupRetention();
    }

    function scanDuplicatesBatch(afterId, reset) {
        var $btn = $('#imp-scan-duplicates');
        var scannedLabel = uiText('scanning_msg', 'Scanning...');
        $('#imp-duplicates-result').html(
            '<div class="imp-dup-scan-progress">' +
            '<div class="imp-loading" id="imp-dup-scan-text">' + escHtml(scannedLabel) + '</div>' +
            '<div class="imp-progress-bar" style="margin-top:10px;"><div class="imp-progress-fill" id="imp-dup-scan-fill" style="width:0%"></div></div>' +
            '</div>'
        );

        ajax('tsoimma_scan_duplicates', {
            after_id: afterId || 0,
            batch: 100,
            reset: reset ? 1 : 0
        }, function(data) {
            var total   = parseInt(data.total, 10) || 0;
            var scanned = parseInt(data.scanned, 10) || 0;
            var pct     = total > 0 ? Math.min(100, Math.round(scanned / total * 100)) : 0;

            $('#imp-dup-scan-text').text(scannedLabel + ' ' + scanned + '/' + total + '...');
            $('#imp-dup-scan-fill').css('width', pct + '%');

            if (!data.done) {
                setTimeout(function() {
                    scanDuplicatesBatch(parseInt(data.after_id, 10) || afterId, false);
                }, 50);
                return;
            }

            $btn.prop('disabled', false);
            if (!data.group_count) {
                $('#imp-duplicates-result').html('<p style="color:var(--imp-success);">✓ ' + uiText('dash_dup_none', 'No duplicate groups found.') + '</p>');
                return;
            }
            $('#imp-duplicates-result').html(
                '<p><strong>' + data.group_count + ' ' + uiText('dash_dup_groups', 'duplicate groups') +
                '</strong> · ' + uiText('dash_dup_wasted', 'Wasted space') + ': ' + escHtml(data.wasted_h || '0 B') +
                ' · ' + escHtml(data.scanned || 0) + ' ' + uiText('dash_scanned', 'scanned') + '</p>' +
                '<p class="imp-dup-intro">' + uiText('dash_dup_explain', 'Each group is identical file content registered more than once in the Media Library.') + '</p>' +
                '<p class="imp-dup-intro" style="opacity:.75;">' + uiText('dash_dup_rescan_hint', 'This data is from the last scan. If you just edited a post, click "Scan duplicates" again to see the current status.') + '</p>'
            );
            renderDuplicateGroups(data.groups || []);
        }, function(err) {
            $btn.prop('disabled', false);
            $('#imp-duplicates-result').html('<div class="imp-error">' + escHtml(err) + '</div>');
        }, 180000);
    }

    function renderDuplicateUsedIn(item) {
        var html = '';

        if (item.keep_recommended) {
            html += '<div class="imp-dup-badge imp-dup-badge-keep">' + escHtml(uiText('dash_dup_keep', 'Keep this one (used in content)')) + '</div>';
        } else if (item.attached_only) {
            // Only a post_parent "attached to" link, nothing actually
            // embeds it — distinct from both "used in content" and "safe
            // to delete" since a human should still glance at it.
            html += '<div class="imp-dup-badge imp-dup-badge-attached">' + escHtml(uiText('dash_dup_attached_only', 'Only attached (not shown in content)')) + '</div>';
        } else if (item.safe_to_delete) {
            html += '<div class="imp-dup-badge imp-dup-badge-delete">' + escHtml(uiText('dash_dup_delete_ok', 'Safe to delete (unused duplicate)')) + '</div>';
        }

        var direct = item.used_in_direct || [];
        if (direct.length) {
            html += '<div class="imp-dup-refs imp-dup-refs-direct"><span class="imp-dup-refs-label">' +
                escHtml(uiText('dash_dup_used_direct', 'Used in content')) + ':</span> ';
            direct.forEach(function(ref) {
                var editUrl = ref.edit_url || (TSOIMMA.site_url + '/wp-admin/post.php?post=' + ref.id + '&action=edit');
                var detail  = ref.detail ? (' · ' + ref.detail + ' · #' + item.id) : (' · #' + item.id);
                html += '<a href="' + escHtml(editUrl) + '" target="_blank" rel="noopener" class="imp-dup-ref-link imp-dup-ref-direct" title="' +
                    escHtml(ref.title || '') + '">' + escHtml(ref.title || ('#' + ref.id)) + escHtml(detail) + '</a> ';
            });
            html += '</div>';
        }

        var indirect = item.used_in_indirect || [];
        if (indirect.length) {
            html += '<div class="imp-dup-refs imp-dup-refs-indirect"><span class="imp-dup-refs-label">' +
                escHtml(uiText('dash_dup_used_indirect', 'Filename match only (this ID is not in the code)')) + ':</span><ul class="imp-dup-indirect-list">';
            indirect.forEach(function(ref) {
                html += '<li>';
                if (ref.detail) {
                    html += escHtml(ref.detail) + ' — ';
                }
                html += escHtml(ref.title || ('#' + ref.id));
                if (ref.active_attachment_id) {
                    html += ' · ' + escHtml(uiText('dash_dup_active_id', 'Active ID in post')) + ' #' + ref.active_attachment_id;
                }
                html += ' · <a href="' + escHtml(ref.edit_url || (TSOIMMA.site_url + '/wp-admin/post.php?post=' + ref.id + '&action=edit')) +
                    '" target="_blank" rel="noopener" class="imp-dup-ref-link imp-dup-ref-indirect">' + escHtml(uiText('dash_dup_open_post', 'Open post')) + '</a>';
                html += '</li>';
            });
            html += '</ul></div>';
        }

        if (!direct.length && !indirect.length) {
            html += '<div class="imp-dup-refs imp-dup-refs-empty">' + escHtml(uiText('dash_dup_refs_none', 'Not referenced in content (may be orphan)')) + '</div>';
        } else if ((item.used_in_more || 0) > 0) {
            html += '<div class="imp-dup-refs-more">+' + item.used_in_more + ' ' + escHtml(uiText('dash_dup_refs_more', 'more')) + '</div>';
        }

        return html;
    }

    // An "only attached" item is also "safe to delete" (see
    // enrich_item_usage() in class-duplicate-finder.php — a bare
    // post_parent link never blocks deletion), so both buttons can show
    // together: Unlink just clears the library link and keeps the file;
    // Delete removes the file outright (detaching first server-side if
    // needed, same as WordPress's own Media Library).
    function renderDuplicateCondActions(item) {
        var html = '';
        if (item.attached_only) {
            html += '<button type="button" class="imp-btn imp-btn-sm imp-btn-ghost imp-dup-detach-btn" data-id="' + item.id + '">' + escHtml(uiText('dash_dup_detach', 'Unlink')) + '</button>';
        }
        if (item.safe_to_delete) {
            html += '<button type="button" class="imp-btn imp-btn-sm imp-btn-danger imp-dup-delete-btn" data-id="' + item.id + '">' + escHtml(uiText('dash_dup_delete', 'Delete')) + '</button>';
        }
        return html;
    }

    // Keep the "Merge duplicates" button (and its hint text) in sync with the
    // "Keep this one" checkboxes: merging only makes sense when at least one
    // item is kept AND at least one is left to delete. Checking every item
    // (nothing to remove) or none of them (nothing to repoint to) used to
    // leave the button clickable but silently do nothing — confusing when a
    // duplicate is deliberately kept in more than one place. Called once
    // right after a group renders and again on every checkbox change.
    function updateDupMergeState($group) {
        var groupIndex = $group.data('group-index');
        var $btn = $group.find('.imp-dup-merge-btn');
        var $hint = $group.find('.imp-dup-merge-hint');
        if (!$btn.length) {
            return;
        }
        if ($hint.data('imp-default-text') === undefined) {
            $hint.data('imp-default-text', $hint.text());
        }
        var $checks = $group.find('input[name="imp-dup-keep-' + groupIndex + '"]');
        var total = $checks.length;
        var checked = $checks.filter(':checked').length;

        if (total > 0 && checked >= total) {
            $btn.prop('disabled', true);
            $hint.text(uiText('dash_dup_merge_all_kept', 'Every file is marked to keep — there is nothing left to merge or delete.'));
        } else if (checked === 0) {
            $btn.prop('disabled', true);
            $hint.text(uiText('dash_dup_merge_none_kept', 'Check at least one file to keep before merging.'));
        } else {
            $btn.prop('disabled', false);
            $hint.text($hint.data('imp-default-text'));
        }
    }

    function renderDuplicateGroups(groups) {
        var $list = $('#imp-duplicates-list');
        $list.empty();
        groups.slice(0, 20).forEach(function(group, groupIndex) {
            var itemCount = (group.items || []).length;
            var header = uiText('dash_dup_group_label', 'Identical file') + ' #' + (groupIndex + 1) +
                ' · ' + escHtml(group.size_h || '') +
                ' · <span class="imp-dup-group-entries-count">' + itemCount + '</span> ' + uiText('dash_dup_entries', 'entries') +
                ' · ' + escHtml(group.wasted_h || '') + ' ' + uiText('dash_dup_wasted_in_group', 'wasted in this group');
            var itemIds = (group.items || []).map(function(it) { return it.id; }).join(',');
            var html = '<div class="imp-dup-group" data-group-index="' + groupIndex + '" data-item-ids="' + escHtml(itemIds) + '">' +
                '<div class="imp-dup-group-header"><strong>' + header + '</strong>' +
                (group.same_filename ? '<div class="imp-dup-group-note">' + escHtml(uiText('dash_dup_same_name', 'Same filename but different Media Library IDs.')) + '</div>' : '') +
                '<div class="imp-dup-group-hash" title="' + escHtml(group.hash || '') + '">MD5: ' + escHtml((group.hash || '').slice(0, 12)) + '…</div>' +
                (itemCount > 1 ?
                    '<div class="imp-dup-merge-row">' +
                    '<button type="button" class="imp-btn imp-btn-sm imp-btn-success imp-dup-merge-btn">' + escHtml(uiText('dash_dup_merge', 'Merge duplicates')) + '</button>' +
                    '<span class="imp-dup-merge-hint">' + escHtml(uiText('dash_dup_merge_hint', 'Keeps the file(s) checked below (you can check more than one), repoints every post/page that used the rest to the first kept file, then deletes the redundant files.')) + '</span>' +
                    '</div>' : '') +
                '</div>';
            var groupHasRecommendedKeep = (group.items || []).some(function(it) { return !!it.keep_recommended; });
            (group.items || []).forEach(function(item, itemIndex) {
                var dims = (item.width && item.height) ? (item.width + '×' + item.height + 'px') : '—';
                html += '<div class="imp-dup-item" data-item-id="' + item.id + '">' +
                    '<div class="imp-dup-thumb"><img src="' + escHtml(item.thumb || '') + '" alt=""></div>' +
                    '<div class="imp-dup-body">' +
                    '<div class="imp-dup-title"><strong>#' + item.id + '</strong> ' + escHtml(item.filename || '') + '</div>' +
                    '<div class="imp-dup-meta">' +
                    '<span><strong>' + escHtml(uiText('dash_dup_upload_path', 'Location')) + ':</strong> <code>' + escHtml(item.rel_path || item.filename || '') + '</code></span><br>' +
                    '<span><strong>' + escHtml(uiText('dash_dup_dimensions', 'Dimensions')) + ':</strong> ' + escHtml(dims) +
                    ' · <strong>' + escHtml(item.filesize_h || '') + '</strong>' +
                    (item.uploaded_h ? ' · <strong>' + escHtml(uiText('dash_dup_uploaded', 'Uploaded')) + ':</strong> ' + escHtml(item.uploaded_h) : '') +
                    '</span>' +
                    (item.title ? '<br><span><strong>' + escHtml(uiText('dash_dup_item_title', 'Title')) + ':</strong> ' + escHtml(item.title) + '</span>' : '') +
                    '</div>' +
                    '<div class="imp-dup-usedin-wrap">' + renderDuplicateUsedIn(item) + '</div>' +
                    '</div>' +
                    '<div class="imp-dup-actions">' +
                    (itemCount > 1 ?
                        '<label class="imp-dup-keep-radio"><input type="checkbox" class="imp-dup-keep-check" name="imp-dup-keep-' + groupIndex + '" value="' + item.id + '"' +
                        ( (item.keep_recommended || (!groupHasRecommendedKeep && itemIndex === 0)) ? ' checked' : '' ) + '> ' +
                        escHtml(uiText('dash_dup_keep_this', 'Keep this one')) + '</label>' : '') +
                    '<button type="button" class="imp-btn imp-btn-sm imp-btn-ghost imp-dup-open-im" data-id="' + item.id + '">' + escHtml(uiText('dash_dup_open_im', 'Open in Image Master')) + '</button>' +
                    '<a href="' + escHtml(item.edit_url || '#') + '" target="_blank" rel="noopener" class="imp-btn imp-btn-sm imp-btn-ghost">' + escHtml(uiText('dash_dup_open_wp', 'WP Media')) + '</a>' +
                    '<span class="imp-dup-cond-actions">' + renderDuplicateCondActions(item) + '</span>' +
                    '</div></div>';
            });
            html += '</div>';
            var $appended = $(html).appendTo($list);
            if (itemCount > 1) {
                updateDupMergeState($appended);
            }
        });
    }

    function renderQueueStatus(queue) {
        var $el = $('#imp-queue-status');
        if (!$el.length) return;
        if (!queue.total) {
            $el.html('<span style="color:var(--imp-text-muted);">' + uiText('dash_queue_empty', 'Queue is empty.') + '</span>');
            $('#imp-queue-cancel').prop('disabled', true);
            return;
        }
        $el.html(
            (function() {
                var parts = [
                    (queue.done || 0) + '/' + queue.total + ' ' + uiText('dash_queue_done', 'done')
                ];
                if (queue.processing > 0) {
                    parts.push((queue.processing || 0) + ' ' + uiText('dash_queue_processing', 'processing'));
                }
                if (queue.thumbs_pending > 0) {
                    parts.push((queue.thumbs_pending || 0) + ' ' + uiText('dash_queue_thumbs', 'thumbnails pending'));
                }
                if (queue.pending > 0) {
                    parts.push((queue.pending || 0) + ' ' + uiText('dash_queue_pending', 'pending'));
                }
                if (queue.errors) {
                    parts.push(queue.errors + ' ' + uiText('dash_queue_errors', 'errors'));
                }
                return '<span>' + escHtml(parts.join(' · ')) + '</span>';
            })()
        );
        $('#imp-queue-cancel').prop('disabled', !(queue.pending > 0 || queue.thumbs_pending > 0));
    }

    function loadBackupRetention() {
        ajax('tsoimma_get_backup_retention', {}, function(data) {
            $('#imp-backup-days').val(data.days || 0);
            $('#imp-backup-max-mb').val(data.max_mb || 0);
        });
    }

    function pollQueueStatus() {
        ajax('tsoimma_get_queue_status', {}, function(data) {
            if ($('#tab-dashboard').hasClass('active')) {
                renderQueueStatus(data);
            }
            if (data.running) {
                if ($('#tab-dashboard').hasClass('active')) {
                    setTimeout(pollQueueStatus, 4000);
                } else {
                    markDashboardDirty();
                }
            } else if (data.total > 0) {
                markDashboardDirty();
                if ($('#tab-dashboard').hasClass('active')) {
                    refreshDashboardTab(false);
                }
            }
        });
    }

    function loadDashboardOverview(onDone) {
        var $stats = $('#imp-dashboard-stats');
        var $engines = $('#imp-dashboard-engines');
        $stats.html('<div class="imp-loading">' + uiText('loading_data', 'Loading...') + '</div>');
        state.dashboard.xhrOverview = ajax('tsoimma_get_dashboard_overview', {}, function(data) {
            state.dashboard.xhrOverview = null;
            var altPending = !!data.missing_alt_pending || data.missing_alt == null;
            var altCount = altPending ? null : (parseInt(data.missing_alt, 10) || 0);
            var warnAlt = !altPending && altCount > 0;
            var fmtKey = String(data.auto_format || 'webp').toLowerCase();
            var fmtLabels = { webp: 'WebP', avif: 'AVIF', jpg: 'JPG', png: 'PNG', original: uiText('fmt_keep', 'Original') };
            var fmtLabel = fmtLabels[fmtKey] || fmtKey.toUpperCase();
            var autoSub = data.auto_enabled
                ? uiText('dash_auto_format_sub', 'Output format: %s').replace('%s', fmtLabel)
                : uiText('dash_auto_disabled_hint', 'Disabled — enable in Auto tab');
            var cards = [
                { label: uiText('dash_total_images', 'Images in library'), val: data.total_images || 0, sub: '\u00a0', cls: '' },
                { label: uiText('dash_missing_alt', 'Missing alt text'), val: altPending ? '…' : altCount, sub: altPending ? uiText('loading_data', 'Loading...') : (warnAlt ? uiText('dash_alt_title', 'Review below') : '✓'), cls: altPending ? '' : (warnAlt ? 'is-warn is-clickable imp-dash-jump' : 'is-ok'), jump: 'dashboard', statKey: 'missing_alt' },
                { label: uiText('dash_backups', 'TSO backups'), val: data.backup_count || 0, sub: data.backup_bytes_h || '0 B', cls: (data.backup_count || 0) > 0 ? 'is-warn' : 'is-ok' },
                { label: uiText('dash_saved', 'Space saved'), val: data.total_saved_h || '0 B', sub: (data.total_operations || 0) + ' ' + uiText('dash_operations', 'operations'), cls: 'is-ok' },
                { label: uiText('dash_auto_label', 'Auto-optimize'), val: data.auto_enabled ? uiText('dash_auto_status_on', 'ON') : uiText('dash_auto_status_off', 'OFF'), sub: autoSub, cls: data.auto_enabled ? 'is-ok is-clickable imp-dash-jump' : '', jump: 'auto' }
            ];
            $stats.empty();
            cards.forEach(function(card) {
                var attrs = card.jump ? ' data-jump-tab="' + card.jump + '"' : '';
                attrs += card.statKey ? ' data-stat-key="' + card.statKey + '"' : '';
                $stats.append(
                    '<div class="imp-stat-card ' + (card.cls || '') + '"' + attrs + '>' +
                    '<span class="imp-stat-label">' + escHtml(card.label) + '</span>' +
                    '<span class="imp-stat-val">' + escHtml(String(card.val)) + '</span>' +
                    (card.sub ? '<span class="imp-stat-sub">' + escHtml(card.sub) + '</span>' : '') +
                    '</div>'
                );
            });

            var engines = data.engines || {};
            state.dashboard.engines = engines;
            var pills = [
                { key: 'gd_webp', label: uiText('dash_engine_gd', 'GD WebP'), descKey: 'dash_engine_gd_desc' },
                { key: 'gd_avif', label: uiText('dash_engine_avif', 'GD AVIF'), descKey: 'dash_engine_avif_desc' },
                { key: 'ghostscript', label: uiText('dash_engine_gs', 'GhostScript'), descKey: 'dash_engine_gs_desc' },
                { key: 'imagick', label: uiText('dash_engine_imagick', 'Imagick'), descKey: 'dash_engine_imagick_desc' }
            ];
            $engines.empty();
            $engines.append('<span class="imp-engine-row-title">' + escHtml(uiText('dash_engine_info_title', 'Server engines')) + '</span>');
            pills.forEach(function(pill) {
                var engineData = engines[pill.key];
                var ok = (engineData && typeof engineData === 'object') ? !!engineData.ok : !!engineData;
                $engines.append(
                    '<button type="button" class="imp-engine-pill ' + (ok ? 'ok' : 'nok') + '" ' +
                    'data-engine-key="' + escHtml(pill.key) + '" ' +
                    'data-engine-label="' + escHtml(pill.label) + '" ' +
                    'data-engine-desc="' + escHtml(uiText(pill.descKey, '')) + '">' +
                    (ok ? '✓' : '✗') + ' ' + escHtml(pill.label) +
                    '</button>'
                );
            });

            renderQueueStatus(data.queue || {});
            state.dashboard.overviewLoaded = true;
            if (data.queue && data.queue.running) {
                pollQueueStatus();
            }
            if (onDone) {
                onDone();
            }
        }, function(err) {
            state.dashboard.xhrOverview = null;
            $stats.html('<div class="imp-error">' + escHtml(err) + '</div>');
            $engines.empty();
            if (onDone) {
                onDone();
            }
        }, 180000);
    }

    function initEngineInfo() {
        $(document).on('click', '.imp-engine-pill', function() {
            var key = $(this).attr('data-engine-key') || '';
            var label = $(this).attr('data-engine-label') || '';
            var desc = $(this).attr('data-engine-desc') || '';
            var engineData = state.dashboard.engines[key] || {};
            var ok = (engineData && typeof engineData === 'object') ? !!engineData.ok : !!engineData;
            var reason = (engineData && typeof engineData === 'object') ? (engineData.reason || '') : '';
            $('#imp-engine-info-title').text(label);
            $('#imp-engine-info-desc').text(desc);
            $('#imp-engine-info-status')
                .attr('class', 'imp-engine-info-status ' + (ok ? 'ok' : 'nok'))
                .text((ok ? '✓ ' : '✗ ') + uiText(ok ? 'dash_engine_available' : 'dash_engine_unavailable', ok ? 'Available' : 'Not available'));
            if (!ok && reason) {
                $('#imp-engine-info-reason').text(reason);
                $('#imp-engine-info-reason-wrap').show();
            } else {
                $('#imp-engine-info-reason-wrap').hide();
            }
            $('#imp-engine-info-modal').show();
        });
        $(document).on('click', '.imp-engine-info-close', function() {
            $('#imp-engine-info-modal').hide();
        });
    }

    function initHelpTips() {
        var $popover = null;
        function hidePopover() {
            if ($popover) {
                $popover.remove();
                $popover = null;
            }
        }
        $(document).on('click', '.imp-help-tip', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var key = $(this).data('help');
            var text = uiText(key, '');
            if (!text) return;
            hidePopover();
            $popover = $('<div class="imp-help-popover" role="tooltip"></div>').text(text).appendTo('body');
            var rect = this.getBoundingClientRect();
            var top = rect.bottom + window.scrollY + 8;
            var left = Math.max(12, rect.left + window.scrollX - 40);
            $popover.css({ top: top + 'px', left: left + 'px' });
        });
        $(document).on('click', function() { hidePopover(); });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') hidePopover();
        });
    }

    function getAltSuggestInputValue(item) {
        if (item.suggested_alt) {
            return item.suggested_alt;
        }
        if (item.alt) {
            return item.alt;
        }
        return '';
    }

    function getAltDraftFromRow(attachmentId) {
        var id = parseInt(attachmentId, 10);
        if (!id) {
            return '';
        }
        var $row = $('.imp-alt-row[data-attachment-id="' + id + '"]');
        if ($row.length) {
            var draft = $.trim(String($row.attr('data-draft-alt') || ''));
            if (draft) {
                return draft;
            }
            return $.trim($row.find('.imp-alt-suggest-text').text() || '');
        }
        if (state.dashboard.altDraftById && state.dashboard.altDraftById[id]) {
            return $.trim(String(state.dashboard.altDraftById[id]));
        }
        return '';
    }

    function rememberAltDraft(attachmentId, altText) {
        var id = parseInt(attachmentId, 10);
        if (!id) {
            return;
        }
        if (!state.dashboard.altDraftById) {
            state.dashboard.altDraftById = {};
        }
        state.dashboard.altDraftById[id] = $.trim(String(altText || ''));
    }

    function adjustDashboardStat(key, delta) {
        var $card = $('#imp-dashboard-stats .imp-stat-card[data-stat-key="' + key + '"]');
        if (!$card.length) {
            return;
        }
        var $val = $card.find('.imp-stat-val');
        var n = parseInt($val.text(), 10) || 0;
        n = Math.max(0, n + delta);
        setDashboardStatValue(key, n);
    }

    function setDashboardStatValue(key, value) {
        var $card = $('#imp-dashboard-stats .imp-stat-card[data-stat-key="' + key + '"]');
        if (!$card.length) {
            return;
        }
        var n = Math.max(0, parseInt(value, 10) || 0);
        $card.find('.imp-stat-val').text(String(n));
        if (key === 'missing_alt') {
            if (n === 0) {
                $card.removeClass('is-warn is-clickable imp-dash-jump').addClass('is-ok');
                $card.find('.imp-stat-sub').text('\u2713');
            } else {
                $card.addClass('is-warn is-clickable imp-dash-jump').removeClass('is-ok');
                $card.find('.imp-stat-sub').text(uiText('dash_alt_title', 'Review below'));
            }
        }
    }

    function removeAltRowAfterSave($row) {
        hideImgHoverPreview();
        var id = parseInt($row.attr('data-attachment-id'), 10);
        if (id) {
            state.dashboard.altSelected.delete(id);
        }
        $row.stop(true, true).fadeOut(220, function() {
            $(this).remove();
            var $grid = $('#imp-alt-grid');
            if (!$grid.find('.imp-alt-row').length) {
                // The current page is now empty, but that does NOT mean
                // there is nothing left overall \u2014 other pages can still
                // have pending images (this was exactly the "all done"
                // shown while 6 more pages were still waiting" bug).
                // Re-fetch from the server instead of assuming: loadMissingAlt()
                // already knows how to fall back to the last valid page, or
                // show the real "all done" state when total is actually 0.
                loadMissingAlt();
            }
        });
        markDashboardDirty();
    }

    function focusAltInputAtEnd($input) {
        var el = $input && $input[0];
        if (!el) {
            return;
        }
        el.focus();
        var len = (el.value || '').length;
        if (typeof el.setSelectionRange === 'function') {
            try {
                el.setSelectionRange(len, len);
            } catch (ignore) {}
        }
    }

    function closeAltEditor($row, revert) {
        if (!$row || !$row.length) {
            return;
        }
        if (revert) {
            var shown = $.trim($row.find('.imp-alt-suggest-text').text() || '');
            $row.attr('data-draft-alt', shown);
            $row.find('.imp-alt-suggest-input').val(shown).removeClass('is-error');
        }
        $row.removeClass('is-editing');
        $row.find('.imp-alt-suggest-view').prop('hidden', false);
        $row.find('.imp-alt-suggest-edit').prop('hidden', true);
    }

    function closeAllAltEditors(revert) {
        $('.imp-alt-row.is-editing').each(function() {
            closeAltEditor($(this), revert);
        });
    }

    function openAltEditor($row) {
        if (!$row || !$row.length) {
            return;
        }
        closeAllAltEditors(true);
        var draft = $.trim(String($row.attr('data-draft-alt') || ''));
        var $input = $row.find('.imp-alt-suggest-input');
        $input.val(draft).removeClass('is-error');
        $row.addClass('is-editing');
        $row.find('.imp-alt-suggest-view').prop('hidden', true);
        $row.find('.imp-alt-suggest-edit').prop('hidden', false);
        setTimeout(function() {
            focusAltInputAtEnd($input);
        }, 0);
    }

    function restoreAltRowSaveUi($row) {
        $row.data('saving', false);
        $row.find('.imp-alt-save-one').prop('disabled', false).text(uiText('dash_alt_save', 'Save'));
        $row.find('.imp-alt-cancel-one').prop('disabled', false);
        $row.find('.imp-alt-accept-one').prop('disabled', false);
        $row.find('.imp-alt-edit-one').prop('disabled', false);
    }

    function saveAltForRow($row, altText, fromView) {
        var $input = $row.find('.imp-alt-suggest-input');
        var id = parseInt($row.attr('data-attachment-id'), 10);
        var alt = $.trim(String(altText || ''));
        var saved = $.trim(String($row.attr('data-saved-alt') || ''));

        if ($row.hasClass('is-editing')) {
            $input.removeClass('is-error');
        }

        if (!id) {
            return;
        }
        if (!alt) {
            if ($row.hasClass('is-editing')) {
                $input.addClass('is-error');
            }
            return;
        }

        $row.attr('data-draft-alt', alt);
        $row.find('.imp-alt-suggest-text').text(alt);

        if (alt === saved) {
            if ($row.hasClass('is-editing')) {
                closeAltEditor($row, false);
            }
            if (fromView) {
                removeAltRowAfterSave($row);
            }
            return;
        }
        if ($row.data('saving')) {
            return;
        }

        hideImgHoverPreview();
        $row.data('saving', true);
        $row.find('.imp-alt-save-one').prop('disabled', true).text(uiText('processing', 'Processing...'));
        $row.find('.imp-alt-cancel-one').prop('disabled', true);
        $row.find('.imp-alt-accept-one').prop('disabled', true);
        $row.find('.imp-alt-edit-one').prop('disabled', true);

        ajax('tsoimma_update_seo', {
            attachment_id: id,
            alt: alt,
            context: 'dashboard_alt'
        }, function(data) {
            $row.data('saving', false);
            if (data && typeof data.missing_alt === 'number') {
                setDashboardStatValue('missing_alt', data.missing_alt);
            }
            removeAltRowAfterSave($row);
        }, function(err) {
            restoreAltRowSaveUi($row);
            if ($row.hasClass('is-editing')) {
                $input.addClass('is-error');
            }
            alert((L.error_prefix || 'Error: ') + err);
        });
    }

    function saveAltSuggestRow($row) {
        saveAltForRow($row, $.trim($row.find('.imp-alt-suggest-input').val()), false);
    }

    function acceptAltSuggestRow($row) {
        var alt = $.trim(String($row.attr('data-draft-alt') || ''));
        if (!alt) {
            alt = $.trim($row.find('.imp-alt-suggest-text').text() || '');
        }
        saveAltForRow($row, alt, true);
    }

    function initAltSuggestEditors() {
        $(document).on('click', '.imp-alt-edit-one', function(e) {
            e.preventDefault();
            openAltEditor($(this).closest('.imp-alt-row'));
        });

        $(document).on('mousedown', '.imp-alt-accept-one', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var $row = $btn.closest('.imp-alt-row');
            if ($btn.prop('disabled') || $row.data('saving') || $row.hasClass('is-editing')) {
                return;
            }
            acceptAltSuggestRow($row);
        });

        $(document).on('mousedown', '.imp-alt-save-one', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var $row = $btn.closest('.imp-alt-row');
            if ($btn.prop('disabled') || $row.data('saving')) {
                return;
            }
            saveAltSuggestRow($row);
        });

        $(document).on('mousedown', '.imp-alt-cancel-one', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $row = $(this).closest('.imp-alt-row');
            if ($row.data('saving')) {
                return;
            }
            closeAltEditor($row, true);
        });

        $(document).on('input', '.imp-alt-suggest-input', function() {
            var $row = $(this).closest('.imp-alt-row');
            var draft = $.trim($(this).val());
            $row.attr('data-draft-alt', draft);
            rememberAltDraft($row.attr('data-attachment-id'), draft);
        });

        $(document).on('keydown', '.imp-alt-suggest-input', function(e) {
            var $row = $(this).closest('.imp-alt-row');
            if (e.key === 'Enter') {
                e.preventDefault();
                saveAltSuggestRow($row);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeAltEditor($row, true);
            }
        });
    }

    function loadMissingAlt() {
        hideImgHoverPreview();
        var $grid = $('#imp-alt-grid');
        $grid.html('<div class="imp-loading">' + uiText('loading_data', 'Loading...') + '</div>');
        state.dashboard.xhrAlt = ajax('tsoimma_get_missing_alt', {
            page: state.dashboard.altPage,
            per_page: 35,
            used_only: $('#imp-alt-used-only').is(':checked') ? 1 : 0
        }, function(data) {
            state.dashboard.xhrAlt = null;
            $grid.empty();
            if (!data.items || !data.items.length) {
                // An empty page here can mean two different things: there is
                // truly nothing left with a missing/generic alt (data.total
                // is 0), or the page we asked for no longer exists because
                // filling alt text on a later page shrank total_pages (e.g.
                // we were on page 7 of 7 and finishing it drops it to 6) —
                // in that case jump back to the new last page instead of
                // reporting "all done" while other pages still have work.
                var lastPage = Math.max(1, data.total_pages || 1);
                if (data.total > 0 && state.dashboard.altPage > lastPage) {
                    state.dashboard.altPage = lastPage;
                    loadMissingAlt();
                    return;
                }
                $grid.html('<p style="color:var(--imp-success);padding:12px;">✓ ' + uiText('dash_alt_all_ok', 'All images have useful alt text.') + '</p>');
                $('#imp-alt-pagination, #imp-alt-pagination-top').empty();
                state.dashboard.altListLoaded = true;
                state.dashboard.altListCacheKey = dashboardAltCacheKey();
                setDashboardStatValue('missing_alt', data.total || 0);
                return;
            }
            data.items.forEach(function(item) {
                var checked = state.dashboard.altSelected.has(item.id) ? ' checked' : '';
                var suggestVal = getAltSuggestInputValue(item);
                rememberAltDraft(item.id, suggestVal);
                var thumbUrl = item.thumb || '';
                var fullUrl = item.url || item.thumb || '';
                var usedLabel = item.is_used ? '\u2713' : '\u2014';
                var manualCls = item.needs_manual_alt ? ' is-manual-alt' : '';
                var manualHint = uiText('dash_alt_manual_hint', 'Write alt text…');
                var suggestView = suggestVal
                    ? escHtml(suggestVal)
                    : '<span class="imp-alt-manual-hint">' + escHtml(manualHint) + '</span>';
                var inputPlaceholder = item.needs_manual_alt ? manualHint : '';
                $grid.append(
                    '<div class="imp-alt-row' + manualCls + '" data-attachment-id="' + item.id + '" data-draft-alt="' + escHtml(suggestVal) + '" data-saved-alt="' + escHtml(item.alt || '') + '">' +
                    '<input type="checkbox" class="imp-alt-check" data-id="' + item.id + '"' + checked + '>' +
                    '<img class="imp-alt-row-thumb" src="' + escHtml(thumbUrl) + '" data-full-url="' + escHtml(fullUrl) + '" data-filename="' + escHtml(item.filename || '') + '" alt="">' +
                    '<div><div class="imp-alt-filename">' + escHtml(item.filename || '') + '</div>' +
                    '<div>' + escHtml(item.title || '') + '</div></div>' +
                    '<div class="imp-alt-suggest-cell">' +
                    '<div class="imp-alt-suggest-view">' +
                    '<span class="imp-alt-suggest-text">' + suggestView + '</span>' +
                    '<span class="imp-alt-suggest-actions">' +
                    '<button type="button" class="imp-alt-accept-one imp-btn imp-btn-sm imp-btn-success" data-id="' + item.id + '" title="' + escHtml(uiText('dash_alt_accept', 'Accept suggested alt')) + '" aria-label="' + escHtml(uiText('dash_alt_accept', 'Accept suggested alt')) + '">✓</button>' +
                    '<button type="button" class="imp-alt-edit-one imp-btn imp-btn-sm imp-btn-ghost" data-id="' + item.id + '" title="' + escHtml(uiText('dash_alt_edit', 'Edit alt')) + '" aria-label="' + escHtml(uiText('dash_alt_edit', 'Edit alt')) + '">✎</button>' +
                    '</span></div>' +
                    '<div class="imp-alt-suggest-edit" hidden>' +
                    '<input type="text" class="imp-alt-suggest-input" data-id="' + item.id + '" value="' + escHtml(suggestVal) + '" placeholder="' + escHtml(inputPlaceholder) + '" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="true" aria-label="' + escHtml(uiText('dash_alt_suggested', 'Suggested alt')) + '">' +
                    '<span class="imp-alt-suggest-actions">' +
                    '<button type="button" class="imp-alt-save-one imp-btn imp-btn-sm imp-btn-success" data-id="' + item.id + '">' + escHtml(uiText('dash_alt_save', 'Save')) + '</button>' +
                    '<button type="button" class="imp-alt-cancel-one imp-btn imp-btn-sm imp-btn-ghost" data-id="' + item.id + '" title="' + escHtml(uiText('dash_alt_cancel', 'Cancel')) + '" aria-label="' + escHtml(uiText('dash_alt_cancel', 'Cancel')) + '">✕</button>' +
                    '</span></div>' +
                    '</div>' +
                    '<div class="imp-alt-used">' + usedLabel + '</div>' +
                    '</div>'
                );
            });
            renderAltPagination(data.page, data.total_pages);
            state.dashboard.altListLoaded = true;
            state.dashboard.altListCacheKey = dashboardAltCacheKey();
            if (typeof data.total === 'number') {
                setDashboardStatValue('missing_alt', data.total);
            }
        }, function(err) {
            state.dashboard.xhrAlt = null;
            $grid.html('<div class="imp-error">' + escHtml(err) + '</div>');
        }, 180000);
    }

    function renderAltPagination(page, totalPages) {
        // Rendered twice (top and bottom of the "Missing alt" list) so both
        // stay in sync \u2014 clicking a page number in either one reloads the list.
        var $bars = $('#imp-alt-pagination, #imp-alt-pagination-top');
        $bars.empty();
        if (totalPages <= 1) {
            return;
        }

        var maxButtons = 12;
        var start = Math.max(1, page - Math.floor(maxButtons / 2));
        var end = Math.min(totalPages, start + maxButtons - 1);
        start = Math.max(1, end - maxButtons + 1);

        $bars.each(function() {
            var $p = $(this);
            if (start > 1) {
                $p.append('<span class="imp-alt-page-ellipsis">\u2026</span>');
            }
            for (var i = start; i <= end; i++) {
                var $btn = $('<button class="imp-btn imp-btn-ghost imp-btn-sm" data-page="' + i + '"></button>').text(i);
                if (i === page) {
                    $btn.addClass('active');
                }
                $p.append($btn);
            }
            if (end < totalPages) {
                $p.append('<span class="imp-alt-page-ellipsis">\u2026</span>');
            }
        });
        $bars.off('click', 'button').on('click', 'button', function() {
            state.dashboard.altPage = parseInt($(this).attr('data-page'), 10);
            loadMissingAlt();
        });
    }

    // ================================================================
    // Tabs
    // ================================================================
    function initTabs() {
        $(document).on('click', '.imp-tab', function() {
            var tab = $(this).data('tab');
            if (tab !== 'dashboard') {
                hideImgHoverPreview();
                abortDashboardRequests();
            }
            $('.imp-tab').removeClass('active');
            $(this).addClass('active');
            $('.imp-tab-content').removeClass('active');
            $('#tab-' + tab).addClass('active');
        });
    }

    // ================================================================
    // Quality sliders
    // ================================================================
    function initQualitySliders() {
        $('#imp-quality').on('input', function() { $('#imp-quality-val').text($(this).val()); });
        $('#imp-modal-quality').on('input', function() { $('#imp-modal-quality-val').text($(this).val()); });
    }

    // ================================================================
    // Search with debounce
    // ================================================================
    function syncSearchClearButton($input) {
        var $btn = $input.closest('.imp-search-wrap').find('.imp-search-clear');
        if (!$btn.length) {
            return;
        }
        if ($.trim($input.val()) === '') {
            $btn.prop('hidden', true).attr('tabindex', '-1');
        } else {
            $btn.prop('hidden', false).attr('tabindex', '0');
        }
    }

    function initSearchClearControls() {
        $('.imp-search-wrap .imp-search').each(function() {
            syncSearchClearButton($(this));
        });

        $(document).on('input', '.imp-search-wrap .imp-search', function() {
            syncSearchClearButton($(this));
        });

        $(document).on('click', '.imp-search-clear', function(e) {
            e.preventDefault();
            var $input = $(this).closest('.imp-search-wrap').find('.imp-search');
            if (!$input.length || $.trim($input.val()) === '') {
                return;
            }
            $input.val('');
            syncSearchClearButton($input);
            $input.trigger('input').focus();
        });
    }

    function initSearch() {
        initSearchClearControls();
        var optTimer;
        var seoTimer;
        $('#imp-search-opt').on('input', function() {
            clearTimeout(optTimer);
            var val = $(this).val();
            optTimer = setTimeout(function() {
                state.opt.search = val;
                state.opt.page = 1;
                state.opt.selected.clear();
                loadOptImages();
            }, 400);
        });
        $('#imp-search-seo').on('input', function() {
            clearTimeout(seoTimer);
            var val = $(this).val();
            seoTimer = setTimeout(function() {
                state.seo.search = val;
                state.seo.page = 1;
                loadSeoImages();
            }, 400);
        });
    }

    // ================================================================
    // OPTIMIZE TAB
    // ================================================================
    function initOptimizeTab() {
        $('#imp-select-all').on('click', function() {
            $('#imp-images-grid .imp-image-card').each(function() {
                var id = $(this).data('id');
                state.opt.selected.add(id);
                $(this).addClass('selected').find('.imp-card-checkbox').addClass('checked');
            });
            updateSelectedCount();
        });
        $('#imp-deselect-all').on('click', function() {
            state.opt.selected.clear();
            $('#imp-images-grid .imp-image-card').removeClass('selected');
            $('#imp-images-grid .imp-card-checkbox').removeClass('checked');
            updateSelectedCount();
        });
        $(document).on('click', '#imp-images-grid .imp-image-card', function(e) {
            if ($(e.target).hasClass('imp-card-edit-btn')) return;
            var id = $(this).data('id');
            if (state.opt.selected.has(id)) {
                state.opt.selected.delete(id);
                $(this).removeClass('selected').find('.imp-card-checkbox').removeClass('checked');
            } else {
                state.opt.selected.add(id);
                $(this).addClass('selected').find('.imp-card-checkbox').addClass('checked');
            }
            updateSelectedCount();
        });
        $(document).on('click', '#imp-images-grid .imp-card-edit-btn', function(e) {
            e.stopPropagation();
            openModal($(this).data('id'), 'optimize');
        });
        $('#imp-bulk-optimize').on('click', function() {
            var ids = Array.from(state.opt.selected);
            if (!ids.length) { alert(L.no_selection || 'Select at least one image.'); return; }
            bulkOptimize(ids);
        });
        $(document).on('click', '#imp-clear-log', function() {
            $('#imp-log-content').empty();
            $('#imp-opt-log').hide();
        });
        $('#imp-opt-per-page').on('change', function() {
            applyPerPage($(this).val(), true);
        });
    }

    function updateSelectedCount() {
        var n = state.opt.selected.size;
        $('#imp-selected-count').text(n + ' ' + (L.n_selected || 'selected'));
        $('#imp-bulk-optimize').prop('disabled', n === 0);
    }

    function loadOptImages() {
        var grid = $('#imp-images-grid');
        grid.html('<div class="imp-loading">' + (L.loading_images || 'Loading images...') + '</div>');
        ajax('tsoimma_get_images', {
            page: state.opt.page, per_page: state.opt.perPage,
            search: state.opt.search, sort: 'filesize'
        }, function(data) {
            state.opt.totalPages = data.total_pages;
            renderOptGrid(data.items);
            renderPagination('#imp-opt-pagination', state.opt.page, data.total_pages, function(p) {
                state.opt.page = p; loadOptImages();
            });
        }, function(err) {
            grid.html('<div class="imp-loading">' + (L.error_prefix || 'Error: ') + err + '</div>');
        });
    }

    function renderOptGrid(items) {
        var grid = $('#imp-images-grid');
        grid.empty();
        if (!items.length) {
            grid.html('<div class="imp-loading">' + (L.no_images || 'No images found.') + '</div>');
            return;
        }
        items.forEach(function(item) {
            var sel     = state.opt.selected.has(item.id) ? 'selected' : '';
            var checked = sel ? 'checked' : '';
            var ext     = item.mime.replace('image/', '').toUpperCase();
            var card    = $('<div class="imp-image-card ' + sel + '" data-id="' + item.id + '"></div>');
            card.append('<div class="imp-card-checkbox ' + checked + '"></div>');
            card.append('<img class="imp-card-thumb" src="' + escHtml(cacheBustUrl(item.thumb || '', item.id)) + '" alt="' + escHtml(item.title) + '" loading="lazy">');
            card.append(
                '<div class="imp-card-info">' +
                '<div class="imp-card-name">' + escHtml(item.filename) + '</div>' +
                '<div class="imp-card-meta">' +
                '<span class="imp-card-size">' + escHtml(item.filesize) + '</span>' +
                '<span class="imp-card-format">' + escHtml(ext) + '</span>' +
                '</div></div>'
            );
            card.append('<button class="imp-card-edit-btn" data-id="' + item.id + '">' + (L.edit_image || '✏ Edit') + '</button>');
            grid.append(card);
        });
        updateSelectedCount();
    }

    function bulkOptimize(ids) {
        var format  = $('#imp-format').val();
        var quality = $('#imp-quality').val();
        var replace = $('#imp-replace').is(':checked') ? 1 : 0;
        var total   = ids.length;

        if (total > 2) {
            $('#imp-bulk-progress').show();
            $('#imp-progress-fill').css('width', '0%');
            $('#imp-progress-text').text(uiText('dash_queue_queued', 'Queued for background processing...'));
            $('#imp-opt-log').show();
            $('#imp-bulk-optimize').prop('disabled', true);
            ajax('tsoimma_enqueue_optimize_queue', {
                ids: ids, format: format, quality: quality, replace: replace
            }, function(data) {
                $('#imp-bulk-optimize').prop('disabled', false);
                $('#imp-bulk-progress').hide();
                var queued = (typeof data.queued === 'number') ? data.queued : total;
                var skipped = (typeof data.skipped === 'number') ? data.skipped : 0;
                addLog('info', '✓ ' + queued + ' ' + uiText('dash_queue_queued_n', 'images queued.'));
                if (skipped > 0) {
                    addLog('info', '⚠ ' + skipped + ' ' + uiText('dash_queue_skipped_n', 'skipped (already busy or in queue).'));
                }
                renderQueueStatus(data);
                markDashboardDirty();
                $('.imp-tab[data-tab="dashboard"]').trigger('click');
                pollQueueStatus();
            }, function(err) {
                $('#imp-bulk-optimize').prop('disabled', false);
                addLog('err', err);
            });
            return;
        }

        var done    = 0;
        $('#imp-bulk-progress').show();
        $('#imp-progress-fill').css('width', '0%');
        $('#imp-progress-text').text((L.bulk_processing || 'Processing') + ' 0/' + total + '...');
        $('#imp-opt-log').show();
        $('#imp-bulk-optimize').prop('disabled', true);

        function processNext() {
            if (done >= total) {
                $('#imp-bulk-optimize').prop('disabled', false);
                $('#imp-bulk-progress').hide();
                addLog('info', '✓ ' + (L.bulk_done || 'Done') + ': ' + done + '/' + total);
                loadOptImages();
                return;
            }
            var id = ids[done];
            ajax('tsoimma_optimize_image', {
                attachment_id: id, format: format, quality: quality, replace: replace
            }, function(data) {
                function finishBulkItem(thumbErr) {
                    done++;
                    var pct = Math.round(done / total * 100);
                    $('#imp-progress-fill').css('width', pct + '%');
                    $('#imp-progress-text').text((L.bulk_processing || 'Processing') + ' ' + done + '/' + total + '...');
                    if (thumbErr) {
                        addLog('err', '⚠ ID ' + id + ' → ' + (L.optimizing_thumbs || 'Optimizing thumbnails...') + ' (' + thumbErr + ')');
                    } else if (data && data.replaced) {
                        addLog('ok', '✓ ID ' + id + ' \u2192 ' + data.format.toUpperCase() + ' | ' + data.savings_pct + '% ' + (L.optimize_done || 'saved'));
                    } else if (data) {
                        addLog('ok', '✓ ID ' + id + ' \u2192 ' + (L.optimized_no_replace || 'optimized'));
                    }
                    processNext();
                }
                if (data.replaced && data.thumbnails_pending) {
                    ajax('tsoimma_optimize_thumbnails', {
                        attachment_id: id,
                        format: (data.format || format),
                        quality: quality
                    }, function() {
                        finishBulkItem(null);
                    }, function(err) {
                        finishBulkItem(err);
                    }, 300000);
                    return;
                }
                finishBulkItem(null);
            }, function(err) {
                done++;
                addLog('err', '✗ ID ' + id + ' \u2192 ' + err);
                processNext();
            }, 120000);
        }
        processNext();
    }

    // ================================================================
    // ORPHANS TAB
    // ================================================================
    function initOrphansTab() {
        $('#imp-scan-orphans').on('click', function() {
            // Requests stay small (max 200 images) so a slow host never hits its
            // PHP/proxy timeout (503); the selected value only caps the batch size.
            var limit = parseInt($('#imp-orphan-limit').val(), 10);
            if (!limit || limit > 200) { limit = 200; }
            scanOrphans(limit, 0, []);
        });
        $(document).on('click', '#imp-orphans-grid .imp-image-card', function(e) {
            if ($(e.target).hasClass('imp-card-edit-btn')) return;
            var id = $(this).data('id');
            if (state.orphanSelected.has(id)) {
                state.orphanSelected.delete(id);
                $(this).removeClass('selected').find('.imp-card-checkbox').removeClass('checked');
            } else {
                state.orphanSelected.add(id);
                $(this).addClass('selected').find('.imp-card-checkbox').addClass('checked');
            }
            updateOrphanCount();
        });
        $(document).on('click', '#imp-orphans-grid .imp-card-edit-btn', function(e) {
            e.stopPropagation();
            openModal($(this).data('id'), 'seo');
        });
        $('#imp-orphan-select-all').on('click', function() {
            state.orphanSelected.clear();
            state.orphans.found.forEach(function(o) { state.orphanSelected.add(o.id); });
            $('#imp-orphans-grid .imp-image-card').addClass('selected').find('.imp-card-checkbox').addClass('checked');
            updateOrphanCount();
        });
        $('#imp-orphan-deselect').on('click', function() {
            state.orphanSelected.clear();
            $('#imp-orphans-grid .imp-image-card').removeClass('selected').find('.imp-card-checkbox').removeClass('checked');
            updateOrphanCount();
        });
        $('#imp-delete-orphans').on('click', function() {
            var ids = Array.from(state.orphanSelected);
            if (!ids.length) { alert(L.no_selection || 'Select at least one image.'); return; }
            if (!confirm(L.confirm_delete || 'Delete selected images? This cannot be undone.')) return;
            ajax('tsoimma_delete_images', { ids: ids }, function(data) {
                var msg = '✓ ' + data.deleted.length + ' ' + (L.images_deleted || 'images deleted.');
                if (data.errors && data.errors.length) {
                    msg += '\n' + (L.errors_label || 'Errors:') + '\n' + data.errors.map(function(e) { return 'ID ' + e.id + ': ' + e.error; }).join('\n');
                }
                alert(msg);
                // Only drop the ones the backend actually reports as deleted
                // — wp_delete_attachment() can fail per-ID, and blindly
                // removing every attempted ID would hide a still-existing
                // orphan from the admin as if it had been cleaned up.
                var deletedIds = data.deleted.map(function(d) { return d.id; });
                state.orphans.found = state.orphans.found.filter(function(o) { return deletedIds.indexOf(o.id) === -1; });
                state.orphanSelected.clear();
                renderOrphansGrid();
                markDashboardDirty();
            }, function(err) { alert((L.error_prefix || 'Error: ') + err); });
        });
    }

    function scanOrphans(limit, offset, accumulated) {
        $('#imp-orphans-loading').show();
        $('#imp-orphans-result').hide();
        ajax('tsoimma_find_orphans', { limit: limit, offset: offset }, function(data) {
            accumulated = accumulated.concat(data.orphans);
            var scanned = offset + data.total_scanned;
            $('#imp-orphans-progress-text').text((L.scanning_msg || 'Scanning') + ' ' + scanned + '/' + data.total_images + '...');
            if (limit > 0 && scanned < data.total_images && data.total_scanned === limit) {
                setTimeout(function() { scanOrphans(limit, scanned, accumulated); }, 100);
            } else {
                $('#imp-orphans-loading').hide();
                state.orphans.found = accumulated;
                state.orphanSelected.clear();
                renderOrphansGrid();
                $('#imp-orphans-result').show();
            }
        }, function(err) { $('#imp-orphans-loading').hide(); alert((L.error_prefix || 'Error: ') + err); });
    }

    function renderOrphansGrid() {
        var grid = $('#imp-orphans-grid');
        grid.empty();
        var found = state.orphans.found;
        if (!found.length) {
            grid.html('<div class="imp-loading" style="color:var(--imp-success)">✓ ' + (L.no_orphans || 'No orphaned images found.') + '</div>');
            updateOrphanCount();
            return;
        }
        found.forEach(function(item) {
            var ext  = (item.mime || '').replace('image/', '').toUpperCase();
            var card = $('<div class="imp-image-card" data-id="' + item.id + '"></div>');
            card.append('<div class="imp-card-checkbox"></div>');
            card.append('<div class="imp-card-badges"><span class="imp-badge-orphan">ORPHAN</span></div>');
            card.append('<img class="imp-card-thumb" src="' + escHtml(cacheBustUrl(item.thumb || item.url || '', item.id)) + '" alt="' + escHtml(item.title) + '" loading="lazy">');
            card.append(
                '<div class="imp-card-info">' +
                '<div class="imp-card-name">' + escHtml(item.filename) + '</div>' +
                '<div class="imp-card-meta">' +
                '<span class="imp-card-size">' + escHtml(item.filesize_h) + '</span>' +
                '<span class="imp-card-format">' + escHtml(ext) + '</span>' +
                '</div></div>'
            );
            card.append('<button class="imp-card-edit-btn" data-id="' + item.id + '">' + (L.edit_image || '✏ Edit') + '</button>');
            grid.append(card);
        });
        updateOrphanCount();
    }

    function updateOrphanCount() {
        var n   = state.orphans.found.length;
        var sel = state.orphanSelected.size;
        $('#imp-orphan-count').text(n + ' | ' + sel + ' ' + (L.n_selected || 'selected'));
        $('#imp-delete-orphans').prop('disabled', sel === 0);
    }

    // ================================================================
    // ROGUE FILES SCANNER
    // ================================================================
    (function initRogueScanner() {
        var rogueFiles    = [];
        var rogueSelected = new Set();
        var rogueAllowlistExpires = 0;

        function rogueAllowlistNote(data) {
            var hours = data && data.delete_allowlist_hours ? data.delete_allowlist_hours : 24;
            return uiText('rogue_delete_allowlist_note', 'You can delete scanned files for 24 hours (current admin only). Rescan if delete fails.')
                .replace('24', String(hours));
        }

        $('#imp-scan-rogue').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).text(L.btn_scanning || '⏳ Scanning...');
            $('#imp-rogue-result').hide();
            $('#imp-rogue-loading').show();
            ajax('tsoimma_scan_rogue_files', {}, function(data) {
                btn.prop('disabled', false).text(uiText('scan_rogue', '🔍 Scan extra upload files'));
                $('#imp-rogue-loading').hide();
                rogueFiles    = data.files || [];
                rogueSelected = new Set();
                rogueAllowlistExpires = data.delete_allowlist_expires || 0;
                if (!rogueFiles.length) {
                    $('#imp-rogue-result').show();
                    $('#imp-rogue-grid').html('<div class="imp-loading" style="padding:30px 0">✓ ' + (L.no_rogue || 'No extra files found.') + '</div>');
                    $('#imp-rogue-summary').text(rogueAllowlistNote(data));
                    $('#imp-delete-rogue, #imp-rogue-select-all, #imp-rogue-deselect').hide();
                    return;
                }
                $('#imp-rogue-summary').html(
                    escHtml(data.total + ' ' + (L.files_label || 'files') + ' \u00b7 ' + data.total_size_h) +
                    '<br><small style="color:var(--imp-text-muted)">' + escHtml(rogueAllowlistNote(data)) + '</small>'
                );
                $('#imp-delete-rogue, #imp-rogue-select-all, #imp-rogue-deselect').show();
                renderRogueGrid();
                $('#imp-rogue-result').show();
            }, function(err) {
                btn.prop('disabled', false).text(uiText('scan_rogue', '🔍 Scan extra upload files'));
                $('#imp-rogue-loading').hide();
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        $(document).on('click', '.imp-rogue-card', function(e) {
            if ($(e.target).closest('a').length) return;
            var path = $(this).data('path');
            if (rogueSelected.has(path)) {
                rogueSelected.delete(path);
                $(this).removeClass('selected');
            } else {
                rogueSelected.add(path);
                $(this).addClass('selected');
            }
            updateRogueToolbar();
        });

        $('#imp-rogue-select-all').on('click', function() {
            rogueSelected = new Set(rogueFiles.map(function(f) { return f.path; }));
            $('.imp-rogue-card').addClass('selected');
            updateRogueToolbar();
        });
        $('#imp-rogue-deselect').on('click', function() {
            rogueSelected.clear();
            $('.imp-rogue-card').removeClass('selected');
            updateRogueToolbar();
        });

        $('#imp-delete-rogue').on('click', function() {
            if (!rogueSelected.size) return;
            if (!confirm((uiText('confirm_delete_rogue', 'Delete') || 'Delete') + ' ' + rogueSelected.size + ' ' + uiText('file_unit_suffix', 'file(s)?'))) return;
            var btn = $(this);
            btn.prop('disabled', true).text(L.btn_deleting || '⏳ Deleting...');
            // Send path_b64 (base64-encoded absolute paths) for safe UTF-8/latin1 handling
            var paths_b64 = rogueFiles
                .filter(function(f) { return rogueSelected.has(f.path); })
                .map(function(f) { return f.path_b64; });
            ajax('tsoimma_delete_rogue_files', { paths_b64: paths_b64 }, function(data) {
                btn.prop('disabled', false).text(L.delete_rogue || '🗑 Delete selected');
                if (data.rescan_required) {
                    alert(uiText('rogue_rescan_required', 'Some files were skipped: run Scan again before deleting (allowlist expires after 24 hours).'));
                    rogueAllowlistExpires = 0;
                }
                // Only drop files the backend actually deleted from disk —
                // a selected-but-skipped file (not on the allowlist, delete
                // failed...) must stay in the list, matched by the exact
                // path_b64 the server echoes back for each success.
                var deletedB64 = new Set(data.deleted_b64 || []);
                rogueFiles    = rogueFiles.filter(function(f) { return !deletedB64.has(f.path_b64); });
                rogueSelected = new Set();
                if (data.errors && data.errors.length) {
                    alert(uiText('deleted_msg', 'Deleted') + ' ' + data.deleted + '. ' + uiText('errors_label', 'Errors:') + '\n' + data.errors.join('\n'));
                }
                if (!rogueFiles.length) {
                    $('#imp-rogue-grid').html('<div class="imp-loading" style="padding:30px 0">✓ ' + (L.all_rogue_deleted || 'All selected extra files deleted!') + '</div>');
                    $('#imp-rogue-summary').text('');
                    $('#imp-delete-rogue, #imp-rogue-select-all, #imp-rogue-deselect').hide();
                } else {
                    $('#imp-rogue-summary').text(rogueFiles.length + ' ' + (L.files_label || 'files') + ' ' + (L.remaining_label || 'remaining'));
                    renderRogueGrid();
                    updateRogueToolbar();
                }
            }, function(err) {
                btn.prop('disabled', false).text(L.delete_rogue || '🗑 Delete selected');
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        function renderRogueGrid() {
            var grid = $('#imp-rogue-grid');
            if (!rogueFiles.length) { grid.html(''); return; }
            var sorted = rogueFiles.slice().sort(function(a, b) {
                var ta = a.mtime || 0;
                var tb = b.mtime || 0;
                if (tb !== ta) return tb - ta;
                return String(a.filename || '').localeCompare(String(b.filename || ''), undefined, { sensitivity: 'base' });
            });
            var html = '';
            sorted.forEach(function(f) {
                var sel   = rogueSelected.has(f.path) ? ' selected' : '';
                var reasonLabel = uiText('rogue_reason_' + (f.reason_code || ''), f.reason || '');
                var badge = f.priority === 'high'
                    ? '<span class="imp-rogue-badge imp-rogue-badge-high">! ' + escHtml(reasonLabel) + '</span>'
                    : (f.reason_code === 'tso_backup' || f.reason_code === 'generic_backup' || f.reason_code === 'tso_pdf_compressed'
                        ? '<span class="imp-rogue-badge imp-rogue-badge-info">💾 ' + escHtml(reasonLabel) + '</span>'
                        : '<span class="imp-rogue-badge imp-rogue-badge-low">' + escHtml(reasonLabel) + '</span>');
                html += '<div class="imp-rogue-card' + sel + '" data-path="' + escHtml(f.path) + '">' +
                    '<div class="imp-rogue-name" title="' + escHtml(f.path) + '">' + escHtml(f.filename) + '</div>' +
                    badge +
                    '<div class="imp-rogue-meta">' +
                    '<span class="imp-rogue-size">'  + escHtml(f.size_h) + '</span>' +
                    '<span class="imp-rogue-date">'  + escHtml(f.date)   + '</span>' +
                    '<a href="' + escHtml(f.url) + '" target="_blank" rel="noopener" class="imp-rogue-link" title="' + escHtml(L.open_title || 'Open') + '">↗</a>' +
                    '</div></div>';
            });
            grid.html(html);
        }

        function updateRogueToolbar() {
            var n   = rogueFiles.length;
            var sel = rogueSelected.size;
            var summary = n + ' ' + (L.files_label || 'files') + ' | ' + sel + ' ' + (L.n_selected || 'selected');
            if (rogueAllowlistExpires > 0 && Math.floor(Date.now() / 1000) >= rogueAllowlistExpires) {
                summary += ' · ' + uiText('rogue_rescan_required', 'Some files were skipped: run Scan again before deleting (allowlist expires after 24 hours).');
            }
            $('#imp-rogue-summary').text(summary);
            $('#imp-delete-rogue').prop('disabled', sel === 0);
        }

        window.tsoimmaRefreshRogueUi = function() {
            if (!$('#tab-orphans').is(':visible')) return;
            renderRogueGrid();
            updateRogueToolbar();
        };
    })();

    // ================================================================
    // IMAGE SIZES TAB
    // ================================================================
    function initSizesTab() {
        var sizesFound      = [];
        var lastTotalImages = 0;
        var detailSizeName  = '';
        var detailItems     = [];
        var detailTotal     = 0;
        var detailOffset    = 0;
        var detailLimit     = 60;
        var detailSelected  = new Set();

        $('#imp-scan-sizes').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true);
            $('#imp-sizes-result, #imp-sizes-detail').hide();
            $('#imp-sizes-loading').show();
            ajax('tsoimma_scan_sizes', {}, function(data) {
                btn.prop('disabled', false);
                $('#imp-sizes-loading').hide();
                sizesFound      = data.sizes || [];
                lastTotalImages = data.total_images || 0;
                renderSizesTable();
                $('#imp-sizes-result').show();
            }, function(err) {
                btn.prop('disabled', false);
                $('#imp-sizes-loading').hide();
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        // Toggle + (lazy) load the "where are my backups" panel — the
        // question kept coming up because until now the only way to find
        // a size-cleanup backup zip was to remember the path shown in an
        // alert right after deleting. This lists every backup on disk
        // (per-original copies AND size zips) with a direct download link.
        var backupsLoaded = false;
        $('#imp-show-backups').on('click', function() {
            var panel = $('#imp-backups-panel');
            if (panel.is(':visible')) {
                panel.hide();
                return;
            }
            panel.show();
            if (backupsLoaded) return;
            $('#imp-backups-list-wrap').html('<div class="imp-loading">' + escHtml(uiText('sizes_backups_loading', 'Loading...')) + '</div>');
            ajax('tsoimma_list_backups', {}, function(data) {
                backupsLoaded = true;
                renderBackupsList(data);
            }, function(err) {
                $('#imp-backups-list-wrap').html('<div class="imp-loading">' + (L.error_prefix || 'Error: ') + escHtml(err) + '</div>');
            });
        });

        // Re-fetch the backups list from the server. Called after any
        // action that can create or remove a backup (size cleanup), so the
        // panel never needs a manual page reload (F5) to show the new zip.
        function refreshBackupsList(retry) {
            backupsLoaded = false;
            if (!$('#imp-backups-panel').is(':visible')) return;
            var before = $('#imp-backups-list-wrap tbody tr').length;
            ajax('tsoimma_list_backups', { _ts: Date.now() }, function(data) {
                backupsLoaded = true;
                renderBackupsList(data);
                // The zip was written just before this request; if the
                // host's filesystem hasn't exposed it yet, look once more.
                if (!retry && (data.items || []).length <= before) {
                    setTimeout(function() { refreshBackupsList(true); }, 1500);
                }
            }, function(err) {
                if (window.console) { console.error('[TSOIMMA] backups refresh failed:', err); }
                if (!retry) {
                    setTimeout(function() { refreshBackupsList(true); }, 1500);
                }
            });
        }

        function renderBackupsList(data) {
            var items = data.items || [];
            if (!items.length) {
                $('#imp-backups-summary').text('');
                $('#imp-backups-list-wrap').html('<div class="imp-loading" style="color:var(--imp-success)">✓ ' + escHtml(uiText('sizes_no_backups', 'No backup files found.')) + '</div>');
                return;
            }

            $('#imp-backups-summary').text(
                data.total_count + ' ' + uiText('sizes_backups_count_label', 'backup file(s)') + ' · ' + (data.total_bytes_h || '')
            );

            var html = '<div class="imp-history-table-container"><table class="imp-history-table"><thead><tr>' +
                '<th>' + uiText('sizes_col_name', 'Size name') + '</th>' +
                '<th>' + uiText('sizes_backups_col_date', 'Date') + '</th>' +
                '<th>' + uiText('sizes_backups_col_count', 'Files') + '</th>' +
                '<th>' + uiText('sizes_col_space', 'Space') + '</th>' +
                '<th></th>' +
                '</tr></thead><tbody>';
            items.forEach(function(item) {
                html += '<tr>' +
                    '<td style="font-family:var(--imp-mono);font-size:12px">' + escHtml(item.name) + (item.is_size_batch ? ' <span class="imp-badge-orphan" style="background:var(--imp-accent2)">' + escHtml(uiText('sizes_backups_batch_tag', 'size batch')) + '</span>' : '') + '</td>' +
                    '<td>' + escHtml(item.date) + '</td>' +
                    '<td>' + (item.file_count || 0) + '</td>' +
                    '<td>' + escHtml(item.bytes_h) + '</td>' +
                    '<td>' +
                        '<a href="' + escHtml(item.url) + '" class="imp-btn imp-btn-sm imp-btn-ghost" target="_blank" rel="noopener">' + escHtml(uiText('sizes_backups_download', 'Download')) + '</a> ' +
                        '<button type="button" class="imp-btn imp-btn-sm imp-btn-danger imp-backups-delete-btn" data-path="' + escHtml(item.relative_path) + '" data-name="' + escHtml(item.name) + '">' + escHtml(uiText('sizes_backups_delete', 'Delete')) + '</button>' +
                    '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            $('#imp-backups-list-wrap').html(html);
        }

        $(document).on('click', '.imp-backups-delete-btn', function() {
            var btn  = $(this);
            var path = btn.data('path');
            var name = btn.data('name');
            var msg = uiText('confirm_delete_backup', 'Permanently delete backup "{name}"? This CANNOT be undone.').replace('{name}', name);
            if (!confirm(msg)) return;

            btn.prop('disabled', true);
            ajax('tsoimma_delete_backup_file', { relative_path: path }, function() {
                btn.closest('tr').remove();
                // Re-fetch instead of just decrementing locally, so the
                // count/total-size line above the table always matches
                // what's actually left on disk.
                backupsLoaded = false;
                ajax('tsoimma_list_backups', {}, function(data) {
                    backupsLoaded = true;
                    renderBackupsList(data);
                }, function() {});
            }, function(err) {
                btn.prop('disabled', false);
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        function renderSizesTable() {
            var wrap = $('#imp-sizes-table-wrap');
            if (!sizesFound.length) {
                wrap.html('<div class="imp-loading" style="color:var(--imp-success)">✓ ' + escHtml(uiText('sizes_none_found', 'No generated size files found.')) + '</div>');
                $('#imp-sizes-summary').text('');
                return;
            }

            // Only show sizes that are actually safe to delete: still
            // registered (in active use) or core-protected (site icon)
            // sizes are never shown here, so every row on screen can be
            // deleted with no further judgment call from the admin.
            var deletable = sizesFound.filter(function(row) {
                return !row.registered && !row.protected;
            });

            if (!deletable.length) {
                wrap.html('<div class="imp-loading" style="color:var(--imp-success)">✓ ' + escHtml(uiText('sizes_all_clean', 'Nothing to delete — every generated size is either still in use or protected by WordPress.')) + '</div>');
                $('#imp-sizes-summary').text('');
                return;
            }

            var totalBytes = deletable.reduce(function(sum, s) { return sum + (s.bytes || 0); }, 0);
            $('#imp-sizes-summary').text(
                deletable.length + ' ' + uiText('sizes_names_label', 'size names') + ' · ' +
                formatBytes(totalBytes) + ' · ' + lastTotalImages + ' ' + uiText('sizes_images_scanned', 'images scanned')
            );

            var html = '<div class="imp-history-table-container"><table class="imp-history-table"><thead><tr>' +
                '<th>' + uiText('sizes_col_name', 'Size name') + '</th>' +
                '<th>' + uiText('sizes_col_dimensions', 'Dimensions') + '</th>' +
                '<th>' + uiText('sizes_col_count', 'Files') + '</th>' +
                '<th>' + uiText('sizes_col_space', 'Space') + '</th>' +
                '<th></th>' +
                '</tr></thead><tbody>';
            deletable.forEach(function(row) {
                html += '<tr>' +
                    '<td style="font-family:var(--imp-mono);font-size:12px">' + escHtml(row.name) + '</td>' +
                    '<td>' + (row.width || row.height ? (row.width + '×' + row.height) : '—') + '</td>' +
                    '<td>' + row.count + '</td>' +
                    '<td>' + escHtml(row.bytes_h) + '</td>' +
                    '<td>' +
                        '<button type="button" class="imp-btn imp-btn-sm imp-btn-ghost imp-sizes-view-btn" data-size="' + escHtml(row.name) + '">' + escHtml(uiText('sizes_view_files', 'View files')) + '</button> ' +
                        '<button type="button" class="imp-btn imp-btn-sm imp-btn-danger imp-sizes-delete-all-btn" data-size="' + escHtml(row.name) + '" data-count="' + row.count + '" data-bytes-h="' + escHtml(row.bytes_h) + '" title="' + escHtml(uiText('sizes_delete_all_tip', 'A backup zip of every file will be saved automatically before deleting.')) + '">' + escHtml(uiText('sizes_delete_all', 'Delete all')) + '</button>' +
                    '</td>' +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            wrap.html(html);
        }

        $(document).on('click', '.imp-sizes-view-btn', function() {
            detailSizeName = $(this).data('size');
            detailItems    = [];
            detailTotal    = 0;
            detailOffset   = 0;
            detailSelected.clear();
            $('#imp-sizes-detail-title').text(uiText('sizes_detail_title_prefix', 'Size:') + ' ' + detailSizeName);
            $('#imp-sizes-detail').show();
            $('html, body').animate({ scrollTop: $('#imp-sizes-detail').offset().top - 40 }, 200);
            loadSizeFiles(true);
        });

        // One-click "delete every file of this size" straight from the
        // summary row, without going through the "View files" grid first.
        // A backup zip is always attempted before deleting (see
        // TSOIMMA_Size_Scanner::delete_size_files()); if it fails, nothing
        // is deleted and the error is shown instead.
        $(document).on('click', '.imp-sizes-delete-all-btn', function() {
            var btn     = $(this);
            var size    = btn.data('size');
            var count   = btn.data('count');
            var bytesH  = btn.data('bytes-h');
            var msg = uiText('confirm_delete_size_all', 'This will permanently delete {n} "{size}" size files ({bytes}). This CANNOT be undone. Continue?')
                .replace('{n}', count).replace('{size}', size).replace('{bytes}', bytesH);
            if (!confirm(msg)) return;

            btn.prop('disabled', true);
            ajax('tsoimma_delete_size_files', { size: size, all: 1 }, function(data) {
                btn.prop('disabled', false);
                var deletedCount = (data.deleted || []).length;

                sizesFound = sizesFound.filter(function(s) { return s.name !== size; });
                renderSizesTable();

                if (detailSizeName === size) {
                    $('#imp-sizes-detail').hide();
                }

                refreshBackupsList();

                var msg2 = '✓ ' + deletedCount + ' ' + uiText('sizes_deleted_msg', 'size file(s) deleted') + ' (' + (data.bytes_freed_h || formatBytes(data.bytes_freed || 0)) + ').';
                if (data.backup_ok && data.backup_zip) {
                    msg2 += '\n' + uiText('sizes_backup_made', 'Backup saved to: {path}').replace('{path}', 'wp-content/uploads/' + data.backup_zip);
                } else if (data.backup_error) {
                    msg2 += '\n⚠ ' + uiText('sizes_backup_failed', 'No backup could be made before deleting') + ': ' + data.backup_error;
                }
                if (data.errors && data.errors.length) {
                    msg2 += '\n' + (L.errors_label || 'Errors:') + '\n' + data.errors.map(function(e) { return 'ID ' + e.id + ': ' + e.error; }).join('\n');
                }
                alert(msg2);
            }, function(err) {
                btn.prop('disabled', false);
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        $('#imp-sizes-back').on('click', function() {
            $('#imp-sizes-detail').hide();
        });

        function loadSizeFiles(reset) {
            $('#imp-sizes-detail-loading').show();
            ajax('tsoimma_list_size_files', { size: detailSizeName, limit: detailLimit, offset: detailOffset }, function(data) {
                $('#imp-sizes-detail-loading').hide();
                detailTotal = data.total || 0;
                detailItems = reset ? (data.items || []) : detailItems.concat(data.items || []);
                // Nothing left for this size (e.g. everything was just deleted):
                // close the detail view instead of leaving an empty grid open.
                if (reset && 0 === detailTotal) {
                    $('#imp-sizes-detail').hide();
                    return;
                }
                renderSizesGrid();
                $('#imp-sizes-load-more').toggle(detailItems.length < detailTotal);
            }, function(err) {
                $('#imp-sizes-detail-loading').hide();
                alert((L.error_prefix || 'Error: ') + err);
            });
        }

        $('#imp-sizes-load-more').on('click', function() {
            detailOffset += detailLimit;
            loadSizeFiles(false);
        });

        function renderSizesGrid() {
            var grid = $('#imp-sizes-grid');
            grid.empty();
            if (!detailItems.length) {
                grid.html('<div class="imp-loading">' + escHtml(uiText('sizes_none_found', 'No generated size files found.')) + '</div>');
                updateSizesToolbar();
                return;
            }
            detailItems.forEach(function(item) {
                var sel  = detailSelected.has(item.id) ? ' selected' : '';
                var card = $('<div class="imp-image-card' + sel + '" data-id="' + item.id + '"></div>');
                card.append('<div class="imp-card-checkbox' + (detailSelected.has(item.id) ? ' checked' : '') + '"></div>');
                card.append('<img class="imp-card-thumb" src="' + escHtml(item.url) + '" alt="" loading="lazy">');
                card.append(
                    '<div class="imp-card-info">' +
                    '<div class="imp-card-name">' + escHtml(item.filename) + '</div>' +
                    '<div class="imp-card-meta">' +
                    '<span class="imp-card-size">' + escHtml(item.bytes_h) + '</span>' +
                    (item.width ? '<span class="imp-card-format">' + item.width + '×' + item.height + '</span>' : '') +
                    '</div></div>'
                );
                grid.append(card);
            });
            updateSizesToolbar();
        }

        $(document).on('click', '#imp-sizes-grid .imp-image-card', function() {
            var id = $(this).data('id');
            if (detailSelected.has(id)) {
                detailSelected.delete(id);
                $(this).removeClass('selected').find('.imp-card-checkbox').removeClass('checked');
            } else {
                detailSelected.add(id);
                $(this).addClass('selected').find('.imp-card-checkbox').addClass('checked');
            }
            updateSizesToolbar();
        });

        $('#imp-sizes-select-all').on('click', function() {
            detailItems.forEach(function(i) { detailSelected.add(i.id); });
            $('#imp-sizes-grid .imp-image-card').addClass('selected').find('.imp-card-checkbox').addClass('checked');
            updateSizesToolbar();
        });
        $('#imp-sizes-deselect').on('click', function() {
            detailSelected.clear();
            $('#imp-sizes-grid .imp-image-card').removeClass('selected').find('.imp-card-checkbox').removeClass('checked');
            updateSizesToolbar();
        });
        $('#imp-sizes-select-sample').on('click', function() {
            var n = 3;
            for (var i = 0; i < detailItems.length && detailSelected.size < n; i++) {
                detailSelected.add(detailItems[i].id);
            }
            renderSizesGrid();
        });

        function updateSizesToolbar() {
            var n = detailSelected.size;
            $('#imp-sizes-detail-count').text(detailTotal + ' | ' + n + ' ' + (L.n_selected || 'selected'));
            $('#imp-sizes-delete-selected').prop('disabled', n === 0);
        }

        $('#imp-sizes-delete-selected').on('click', function() {
            var ids = Array.from(detailSelected);
            if (!ids.length) return;
            var isAll = (detailSelected.size >= detailTotal);
            var msg;
            if (isAll) {
                var bytesSel = detailItems.reduce(function(s, i) { return detailSelected.has(i.id) ? s + (i.bytes || 0) : s; }, 0);
                msg = uiText('confirm_delete_size_all', 'This will permanently delete {n} "{size}" size files ({bytes}). This CANNOT be undone. Continue?')
                    .replace('{n}', ids.length).replace('{size}', detailSizeName).replace('{bytes}', formatBytes(bytesSel));
            } else {
                msg = uiText('confirm_delete_size_sample', 'Delete these {n} "{size}" files as a test? Check that your site still works before deleting the rest.')
                    .replace('{n}', ids.length).replace('{size}', detailSizeName);
            }
            if (!confirm(msg)) return;
            var btn = $(this);
            btn.prop('disabled', true);
            ajax('tsoimma_delete_size_files', { size: detailSizeName, ids: ids }, function(data) {
                btn.prop('disabled', false);
                var deletedIds = (data.deleted || []).map(function(d) { return d.id; });
                detailSelected.clear();
                refreshBackupsList();

                var sizeRow = sizesFound.filter(function(s) { return s.name === detailSizeName; })[0];
                if (sizeRow) {
                    sizeRow.count -= deletedIds.length;
                    sizeRow.bytes  = Math.max(0, sizeRow.bytes - (data.bytes_freed || 0));
                    sizeRow.bytes_h = formatBytes(sizeRow.bytes);
                    if (sizeRow.count <= 0) {
                        sizesFound = sizesFound.filter(function(s) { return s.name !== detailSizeName; });
                    }
                    renderSizesTable();
                }

                var msg2 = '✓ ' + deletedIds.length + ' ' + uiText('sizes_deleted_msg', 'size file(s) deleted') + ' (' + (data.bytes_freed_h || formatBytes(data.bytes_freed || 0)) + ').';
                if (data.backup_ok && data.backup_zip) {
                    msg2 += '\n' + uiText('sizes_backup_made', 'Backup saved to: {path}').replace('{path}', 'wp-content/uploads/' + data.backup_zip);
                } else if (data.backup_error) {
                    msg2 += '\n⚠ ' + uiText('sizes_backup_failed', 'No backup could be made before deleting') + ': ' + data.backup_error;
                }
                if (data.errors && data.errors.length) {
                    msg2 += '\n' + (L.errors_label || 'Errors:') + '\n' + data.errors.map(function(e) { return 'ID ' + e.id + ': ' + e.error; }).join('\n');
                }
                alert(msg2);

                // Re-fetch this size from the server instead of just
                // splicing the deleted rows out of the local page: this is
                // what makes the grid keep showing whatever files remain
                // (continuing into the next batch on its own) rather than
                // going blank and forcing the admin to re-open "View files".
                detailOffset = 0;
                loadSizeFiles(true);
            }, function(err) {
                btn.prop('disabled', false);
                alert((L.error_prefix || 'Error: ') + err);
            });
        });
    }

    // ================================================================
    // SEO TAB
    // ================================================================
    function initSeoTab() {
        $('#imp-seo-sort').on('change', function() {
            state.seo.page = 1; loadSeoImages();
        });
        $('#imp-seo-per-page').on('change', function() {
            applyPerPage($(this).val(), true);
        });
        $(document).on('click', '#imp-seo-grid .imp-image-card', function(e) {
            if ($(e.target).hasClass('imp-card-edit-btn')) return;
            openModal($(this).data('id'), 'seo');
        });
        $(document).on('click', '#imp-seo-grid .imp-card-edit-btn', function(e) {
            e.stopPropagation();
            openModal($(this).data('id'), 'rename');
        });
    }

    function loadSeoImages() {
        var grid = $('#imp-seo-grid');
        var sort = $('#imp-seo-sort').val() || 'filesize';
        grid.html('<div class="imp-loading">' + (L.loading_images || 'Loading...') + '</div>');
        ajax('tsoimma_get_images', {
            page: state.seo.page, per_page: state.seo.perPage,
            search: state.seo.search, sort: sort
        }, function(data) {
            state.seo.totalPages = data.total_pages;
            renderSeoGrid(data.items);
            renderPagination('#imp-seo-pagination', state.seo.page, data.total_pages, function(p) {
                state.seo.page = p; loadSeoImages();
            });
        }, function(err) {
            grid.html('<div class="imp-loading">' + (L.error_prefix || 'Error: ') + err + '</div>');
        });
    }

    function renderSeoGrid(items) {
        var grid = $('#imp-seo-grid');
        grid.empty();
        if (!items.length) { grid.html('<div class="imp-loading">' + (L.no_images || 'No images.') + '</div>'); return; }
        items.forEach(function(item) {
            var ext    = item.ext || item.mime.replace('image/', '').toUpperCase();
            var seoOk  = item.slug_ok && (item.alt_ok === true || (item.alt_ok !== false && item.alt && String(item.alt).trim()));
            var seoCls = seoOk ? 'imp-badge-seo-ok' : 'imp-badge-seo-bad';
            var seoTxt = seoOk ? 'SEO ✓' : 'SEO ✗';
            var card   = $('<div class="imp-image-card" data-id="' + item.id + '" style="cursor:pointer;"></div>');
            card.append('<div class="imp-card-badges"><span class="' + seoCls + '">' + seoTxt + '</span></div>');
            card.append('<img class="imp-card-thumb" src="' + escHtml(cacheBustUrl(item.thumb || '', item.id)) + '" alt="' + escHtml(item.title) + '" loading="lazy">');
            var altWarn = (item.alt_ok === false || (!item.alt && item.alt_ok !== true)) ? '<div style="font-size:10px;color:var(--imp-warn);margin-top:4px;">⚠ ' + (L.no_alt_text || 'No alt text') + '</div>' : '';
            card.append(
                '<div class="imp-card-info">' +
                '<div class="imp-card-name">' + escHtml(item.filename) + '</div>' +
                '<div class="imp-card-meta">' +
                '<span class="imp-card-size">' + escHtml(item.filesize) + '</span>' +
                '<span class="imp-card-format">' + escHtml(ext) + '</span>' +
                '</div>' + altWarn + '</div>'
            );
            card.append('<button class="imp-card-edit-btn" data-id="' + item.id + '">✏ SEO</button>');
            grid.append(card);
        });
    }

    // ================================================================
    // MODAL
    // ================================================================
    function initModal() {
        $(document).on('click', '.imp-modal-close, .imp-modal-overlay', function() {
            hideImgHoverPreview();
            closeModal();
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                hideImgHoverPreview();
                closeModal();
            }
        });
        $(document).on('click', '.imp-mtab', function() {
            var tab = $(this).data('mtab');
            $(this).closest('.imp-modal-tabs').find('.imp-mtab').removeClass('active');
            $(this).addClass('active');
            $(this).closest('.imp-modal-form').find('.imp-mtab-content').removeClass('active');
            $('#mtab-' + tab).addClass('active');
        });

        // Save SEO
        $('#imp-save-seo').on('click', function() {
            var id  = state.currentModalId;
            var btn = $(this);
            btn.prop('disabled', true).text(L.processing || 'Processing...');
            ajax('tsoimma_update_seo', {
                attachment_id: id,
                title: $('#imp-seo-title').val(), alt: $('#imp-seo-alt').val(),
                caption: $('#imp-seo-caption').val(), description: $('#imp-seo-description').val()
            }, function() {
                btn.text(L.save_ok || '✓ Saved!');
                setTimeout(function() { btn.prop('disabled', false).text(L.save_seo || '💾 Save SEO'); }, 1500);
                loadSeoImages();
                markDashboardDirty();
            }, function(err) { btn.prop('disabled', false).text(L.save_seo || '💾 Save SEO'); alert((L.error_prefix || 'Error: ') + err); });
        });

        // Suggest filename
        $('#imp-use-suggested').on('click', function() {
            $('#imp-new-filename').val($('#imp-suggested-name').text());
        });

        // Save rename
        $('#imp-save-rename').on('click', function() {
            var id      = state.currentModalId;
            var newName = $('#imp-new-filename').val().trim();
            if (!newName) { alert(L.enter_name || 'Enter a name.'); return; }
            var btn = $(this);
            btn.prop('disabled', true).text(L.processing || 'Processing...');
            ajax('tsoimma_rename_image', { attachment_id: id, new_name: newName, strict_seo: 0 }, function(data) {
                btn.prop('disabled', false).text(uiText('rename_btn', '✏ Rename file'));
                $('#imp-current-filename').val(data.new_filename);
                alert('✓ ' + data.old_filename + ' \u2192 ' + data.new_filename);
                closeModal();
                loadOptImages();
                loadSeoImages();
            }, function(err) {
                btn.prop('disabled', false).text(uiText('rename_btn', '✏ Rename file'));
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        // Resize toggle
        $('#imp-modal-resize').on('change', function() {
            $('#imp-resize-options').toggle(this.checked);
            if (!this.checked) {
                $('#imp-modal-width').val('');
                $('#imp-modal-height').val('');
                $('.imp-preset-btn').removeClass('active');
            }
        });
        $(document).on('click', '#imp-resize-toggle', function(e) {
            if (!$(e.target).closest('.imp-toggle-switch').length) {
                var cb = $('#imp-modal-resize');
                cb.prop('checked', !cb.prop('checked')).trigger('change');
            }
        });
        $(document).on('click', '.imp-preset-btn', function() {
            $('#imp-modal-width').val($(this).data('w') || '');
            $('#imp-modal-height').val($(this).data('h') || '');
            $('.imp-preset-btn').removeClass('active');
            $(this).addClass('active');
        });
        $('#imp-modal-width, #imp-modal-height').on('input', function() {
            $('.imp-preset-btn').removeClass('active');
        });

        // Optimize single
        $('#imp-optimize-single').on('click', function() {
            var id       = state.currentModalId;
            var btn      = $(this);
            var doResize = $('#imp-modal-resize').is(':checked');
            btn.prop('disabled', true).text(L.processing || 'Processing...');
            ajax('tsoimma_optimize_image', {
                attachment_id: id,
                format:     $('#imp-modal-format').val(),
                quality:    $('#imp-modal-quality').val(),
                replace:    $('#imp-modal-replace').is(':checked') ? 1 : 0,
                max_width:  doResize ? (parseInt($('#imp-modal-width').val(), 10)  || 0) : 0,
                max_height: doResize ? (parseInt($('#imp-modal-height').val(), 10) || 0) : 0
            }, function(data) {
                btn.prop('disabled', false).text(L.optimize_now || '⚡ Optimize now');
                resetModalResizeUi();
                var box = $('#imp-modal-result');
                if (data.replaced) {
                    var bigger   = data.savings_pct <= 0;
                    var cls      = bigger ? 'imp-result-warn' : 'imp-result-ok';
                    var origSize = data.backup_size ? formatBytes(data.backup_size) : formatBytes(data.original_size);
                    var thumbNote = buildThumbStatusHtml(!!data.thumbnails_done);
                    var msg = (bigger ? '⚠ ' : '✓ ') +
                        (bigger ? (L.converted_bigger || 'Converted but larger') : (L.optimized_ok || 'Optimized!')) +
                        '<br>Format: <strong>' + escHtml(String(data.format || '').toUpperCase()) + '</strong><br>' +
                        origSize + ' \u2192 <strong>' + formatBytes(data.new_size) + '</strong>' +
                        (data.savings_pct > 0 ? ' | <strong>' + data.savings_pct + '%</strong>' : '') +
                        thumbNote;
                    box.removeClass('imp-result-err imp-result-ok imp-result-warn').addClass(cls + ' imp-result-box').show().html(msg);
                    state.imgCacheTs[id] = Date.now();
                    if (data.new_url) {
                        var ts = state.imgCacheTs[id];
                        $('#imp-modal-img').attr('src', data.new_url + '?_t=' + ts).attr('data-full-url', data.new_url + '?_t=' + ts);
                    }
                    refreshModalImageInfo(id);
                    loadOptImages();
                    loadSeoImages();
                    if (data.thumbnails_pending) {
                        runModalThumbnailPass(
                            id,
                            data.format || $('#imp-modal-format').val(),
                            $('#imp-modal-quality').val(),
                            box
                        );
                    }
                } else {
                    box.removeClass('imp-result-err imp-result-warn').addClass('imp-result-ok imp-result-box').show()
                       .html('✓ ' + (L.optimized_no_replace || 'Optimized (not replaced).') + ' ' + data.savings_pct + '%');
                }
            }, function(err) {
                btn.prop('disabled', false).text(L.optimize_now || '⚡ Optimize now');
                $('#imp-modal-result').show().removeClass('imp-result-ok imp-result-warn').addClass('imp-result-err imp-result-box').text((L.error_prefix || 'Error: ') + err);
            }, 120000);
        });
    }

    // Revert
    $(document).on('click', '#imp-revert-btn', function() {
        var id  = state.currentModalId;
        var btn = $(this);
        if (!confirm(L.confirm_revert || 'Revert to original? Optimized version will be lost.')) return;
        btn.prop('disabled', true).text(L.btn_reverting || '⏳ Reverting...');
        ajax('tsoimma_revert_image', { attachment_id: id }, function(data) {
            var savedHtml = '↩ ' + (L.reverted_ok || 'Reverted!') + '<br>' + data.restored_ext + ' | ' + data.restored_size_h;
            var savedCls  = 'imp-result-ok imp-result-box';
            refreshModalImageInfo(id);
            $('#imp-modal-result').attr('class', savedCls).html(savedHtml).show();
            loadOptImages();
            loadSeoImages();
        }, function(err) {
            btn.prop('disabled', false).text(L.revert_btn || '↩ Revert');
            var msg = (err === 'backup_mismatch_after_rename')
                ? uiText('revert_rename_mismatch', 'Cannot revert because the file was renamed after optimization.')
                : err;
            alert((L.error_prefix || 'Error: ') + msg);
        });
    });

    // Delete backup
    $(document).on('click', '#imp-delete-backup-btn', function() {
        var id  = state.currentModalId;
        var btn = $(this);
        if (!confirm(L.confirm_del_backup || 'Delete backup? You will not be able to revert.')) return;
        btn.prop('disabled', true).text(L.btn_deleting || '⏳ Deleting...');
        ajax('tsoimma_delete_backup', { attachment_id: id }, function() {
            btn.prop('disabled', false).text(L.del_backup || '🗑 Delete backup');
            refreshModalImageInfo(id);
            $('#imp-modal-result').hide().text('');
            markDashboardDirty();
        }, function(err) { btn.prop('disabled', false).text(L.del_backup || '🗑 Delete backup'); alert((L.error_prefix || 'Error: ') + err); });
    });

    function renderModalStats(data) {
        var backupHtml = data.has_backup
            ? '<div class="imp-stat-box imp-stat-backup" style="border-color:var(--imp-warn);grid-column:1/-1">' +
              '<div class="imp-backup-info"><span>💾 <strong>' + (L.backup_available || 'Backup available') + '</strong>' + (data.backup_size ? ' \u00b7 ' + data.backup_size : '') + '</span></div>' +
              '<button id="imp-revert-btn" class="imp-btn imp-btn-sm imp-btn-danger">' + (L.revert_btn || 'Revert to original') + '</button>' +
              '<button id="imp-delete-backup-btn" class="imp-btn imp-btn-sm" style="border-color:var(--imp-warn);color:var(--imp-warn)">🗑 ' + (L.del_backup || 'Delete backup') + '</button>' +
              '</div>'
            : '';
        $('#imp-modal-stats').html(
            '<div class="imp-stat-box"><span class="imp-stat-label">' + (L.stat_current_size || 'Current size') + '</span><span class="imp-stat-val">' + escHtml(data.filesize_h) + '</span></div>' +
            '<div class="imp-stat-box"><span class="imp-stat-label">' + (L.stat_real_format || 'Format') + '</span><span class="imp-stat-val" style="color:var(--imp-accent2)">' + escHtml(data.ext) + '</span></div>' +
            backupHtml
        );
    }

    function applyModalImageInfo(data) {
        $('#imp-modal-title-head').html('<span class="imp-modal-fname">' + escHtml(data.filename) + '</span>');
        $('#imp-modal-id').val(data.id);
        var ts = state.imgCacheTs[data.id] || Date.now();
        state.imgCacheTs[data.id] = ts;
        $('#imp-modal-img')
            .attr('src', (data.thumb || '') + '?t=' + ts)
            .attr('data-full-url', (data.url || data.thumb || '') + '?t=' + ts)
            .css('cursor', data.url ? 'zoom-in' : '');
        $('#imp-modal-fileinfo').html(
            '<strong>📄</strong> ' + escHtml(data.filename) + '<br>' +
            '<strong>📐</strong> ' + data.width + '\u00d7' + data.height + 'px<br>' +
            '<strong>⚖</strong> '   + escHtml(data.filesize_h) + '<br>' +
            '<strong>🖼</strong> ' + escHtml(data.ext) + ' \u00b7 ' + escHtml(data.mime)
        );
        $('#imp-seo-title').val(data.title || '');
        $('#imp-seo-alt').val(data.alt || '');
        $('#imp-seo-caption').val(data.caption || '');
        $('#imp-seo-description').val(data.description || '');
        renderUsedIn(data.used_in || [], !!data.is_orphan, data.used_in_indirect || []);
        $('#imp-current-filename').val(data.filename);
        $('#imp-new-filename').val('');
        $('#imp-suggested-name').text(data.suggested || '');
        renderModalStats(data);
    }

    function refreshModalImageInfo(id) {
        ajax('tsoimma_get_image_info', { attachment_id: id }, function(data) {
            applyModalImageInfo(data);
        });
    }

    function buildThumbStatusHtml(done) {
        if (done) {
            return '<br><small id="imp-thumb-status" style="color:var(--imp-success)">✓ ' + (L.thumbs_done || 'Thumbnails processed.') + '</small>';
        }
        return '<br><small id="imp-thumb-status" style="color:var(--imp-accent2)">⏳ ' + (L.optimizing_thumbs || 'Optimizing thumbnails...') + '</small>';
    }

    function runModalThumbnailPass(id, format, quality, $resultBox, onDone) {
        var btn = $('#imp-optimize-single');
        btn.prop('disabled', true).text(L.optimizing_thumbs || 'Optimizing thumbnails...');
        ajax('tsoimma_optimize_thumbnails', {
            attachment_id: id,
            format:     format,
            quality:    quality
        }, function(data) {
            btn.prop('disabled', false).text(L.optimize_now || '⚡ Optimize now');
            state.imgCacheTs[id] = Date.now();
            if ($resultBox && $resultBox.length) {
                var doneNote = buildThumbStatusHtml(true);
                $resultBox.html($resultBox.html().replace(/<small id="imp-thumb-status"[^>]*>.*?<\/small>/, doneNote));
            }
            refreshModalImageInfo(id);
            loadOptImages();
            loadSeoImages();
            if (onDone) onDone(data);
        }, function() {
            btn.prop('disabled', false).text(L.optimize_now || '⚡ Optimize now');
            if ($resultBox && $resultBox.length) {
                var warnNote = '<small id="imp-thumb-status" style="color:var(--imp-warn)">⚠ ' + (L.error_prefix || 'Error: ') + (L.optimizing_thumbs || 'Optimizing thumbnails...') + '</small>';
                $resultBox.html($resultBox.html().replace(/<small id="imp-thumb-status"[^>]*>.*?<\/small>/, warnNote));
            }
        }, 300000);
    }

    function resetModalResizeUi() {
        $('#imp-modal-resize').prop('checked', false);
        $('#imp-resize-options').hide();
        $('#imp-modal-width, #imp-modal-height').val('');
        $('.imp-preset-btn').removeClass('active');
    }

    function openModal(id, defaultTab) {
        state.currentModalId = id;
        $('#imp-modal-result').hide().text('');
        resetModalResizeUi();
        $('#imp-modal').show();
        $('.imp-mtab').removeClass('active');
        $('.imp-mtab-content').removeClass('active');
        $('.imp-mtab[data-mtab="' + defaultTab + '"]').addClass('active');
        $('#mtab-' + defaultTab).addClass('active');
        $('#imp-modal-title-head').html('<span style="color:var(--imp-text-muted)">' + (L.loading_modal || 'Loading...') + '</span>');
        $('#imp-modal-img').attr('src', '');
        ajax('tsoimma_get_image_info', { attachment_id: id }, function(data) {
            applyModalImageInfo(data);
        });
    }

    function renderUsedIn(posts, isOrphan, indirectPosts) {
        indirectPosts = indirectPosts || [];
        var $block = $('#imp-used-in-block');
        if (!$block.length) {
            $('#mtab-seo').append('<div id="imp-used-in-block" class="imp-used-in"></div>');
            $block = $('#imp-used-in-block');
        }
        if (!posts || !posts.length) {
            var msg   = isOrphan ? (L.orphan_confirmed || 'Confirmed orphan: not referenced anywhere.') : (L.not_in_content || 'Not found in post_content.');
            var color = isOrphan ? 'var(--imp-danger)' : 'var(--imp-warn)';
            var html0 = '<div class="imp-used-in-title">📎 ' + (L.used_in || 'Used in') + '</div><div class="imp-used-in-empty" style="color:' + color + '">' + msg + '</div>';
            html0 += renderUsedInIndirect(indirectPosts);
            $block.html(html0);
            return;
        }
        var html = '<div class="imp-used-in-title">📎 ' + (L.used_in || 'Used in') + ' (' + posts.length + ')</div><div class="imp-used-in-list">';
        posts.forEach(function(p) {
            var editUrl  = TSOIMMA.site_url + '/wp-admin/post.php?post=' + p.id + '&action=edit';
            var typeLbl  = p.featured ? '⭐ ' + (L.featured || 'Featured') : (p.type === 'post' ? '📝 ' + (L.post_label || 'Post') : '📄 ' + (L.page_label || 'Page'));
            html += '<a href="' + escHtml(editUrl) + '" target="_blank" rel="noopener" class="imp-used-in-item">' +
                '<span class="imp-used-in-type">' + typeLbl + '</span>' +
                '<span class="imp-used-in-item-title">' + escHtml(p.title) + '</span>' +
                '<span style="color:var(--imp-text-muted);font-size:10px;">↗</span>' +
                '</a>';
        });
        html += '</div>';
        html += renderUsedInIndirect(indirectPosts);
        $block.html(html);
    }

    // Unconfirmed matches (a bare block-attribute ID with nothing else
    // corroborating it, or a filename-only coincidence) — shown separately
    // from confirmed usage so they're not mistaken for a genuine reference.
    function renderUsedInIndirect(indirectPosts) {
        if (!indirectPosts || !indirectPosts.length) {
            return '';
        }
        var allParentOnly = indirectPosts.every(function(p) { return !!p.parent_only; });
        var html = '<div class="imp-used-in-title" style="margin-top:10px;">' +
            (allParentOnly
                ? uiText('dash_dup_attached_only', 'Only attached (not shown in content)')
                : uiText('dash_dup_used_indirect', 'Filename match only (this ID is not in the code)')) +
            ' (' + indirectPosts.length + ')</div><div class="imp-used-in-list">';
        indirectPosts.forEach(function(p) {
            var editUrl = p.edit_url || (TSOIMMA.site_url + '/wp-admin/post.php?post=' + p.id + '&action=edit');
            html += '<a href="' + escHtml(editUrl) + '" target="_blank" rel="noopener" class="imp-used-in-item" style="opacity:.7;">' +
                '<span class="imp-used-in-type">?</span>' +
                '<span class="imp-used-in-item-title">' + escHtml(p.title || ('#' + p.id)) + (p.detail ? ' · ' + escHtml(p.detail) : '') + '</span>' +
                '<span style="color:var(--imp-text-muted);font-size:10px;">↗</span>' +
                '</a>';
        });
        html += '</div>';
        return html;
    }

    function closeModal() {
        $('#imp-modal').hide();
        state.currentModalId = null;
    }

    // ================================================================
    // PAGINATION
    // ================================================================
    function renderPagination(selector, current, total, onClick) {
        var $el = $(selector);
        $el.empty();
        if (total <= 1) return;
        for (var i = 1; i <= total; i++) {
            (function(page) {
                var btn = $('<button class="imp-page-btn">' + page + '</button>');
                if (page === current) btn.addClass('active');
                btn.on('click', function() { onClick(page); });
                $el.append(btn);
            })(i);
        }
    }

    // ================================================================
    // AJAX helper
    // ================================================================
    function parseAjaxError(data) {
        if (data == null || data === '') {
            return 'Unknown error.';
        }
        if (typeof data === 'string') {
            return data;
        }
        if (typeof data === 'object') {
            if (data.message) {
                return String(data.message);
            }
            if (data.code && !data.message) {
                return String(data.code);
            }
        }
        return 'Unknown error.';
    }

    function ajax(action, data, onSuccess, onError, timeoutMs) {
        return $.ajax({
            url:     TSOIMMA.ajax_url,
            type:    'POST',
            timeout: timeoutMs || 120000,
            data:    $.extend({ action: action, nonce: TSOIMMA.nonce }, data),
            success: function(resp) {
                if (resp.success) {
                    if (onSuccess) onSuccess(resp.data);
                } else {
                    (onError || defaultError)(parseAjaxError(resp.data));
                }
            },
            error: function(xhr, status) {
                if (status === 'abort') {
                    return;
                }
                if (status === 'timeout') {
                    if (onError) onError('timeout');
                } else {
                    (onError || defaultError)('Network error: ' + xhr.status);
                }
            }
        });
    }

    function defaultError(msg) { console.error('[TSOIMMA]', msg); }

    function addLog(type, msg) {
        $('#imp-opt-log').show();
        $('#imp-log-content').prepend($('<div class="imp-log-entry imp-log-' + type + '"></div>').text(msg));
    }

    function cacheBustUrl(url, id) {
        if (!url) return url;
        var ts = state.imgCacheTs[id];
        if (!ts) return url;
        return url + (url.indexOf('?') === -1 ? '?' : '&') + '_t=' + ts;
    }

    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ================================================================
    // PDF TAB
    // ================================================================
    var pdfState = { selected: new Set(), page: 1, perPage: 30, search: '' };

    function pollPdfStatus(id, btn, $sizeCell, attempt, replace) {
        var POLL_MS = 1500;
        var MAX_ATTEMPTS = 80; // 80 * 1.5s ~= 120s hard client cap
        attempt = attempt || 0;
        replace = replace !== undefined ? replace : 1;

        // Hard client timeout (backend also has its own cap)
        if (attempt > MAX_ATTEMPTS) {
            btn.prop('disabled', false).text(uiText('pdf_compress_btn', '📄 Compress')).css('background', '');
            var timeoutMessage = L.pdf_timeout_msg || 'GhostScript timed out. Check FTP.';
            ajax('tsoimma_mark_pdf_non_compressible', {
                attachment_id: id,
                code: 'client_timeout',
                message: timeoutMessage
            }, function() {
                loadPdfs();
                alert(timeoutMessage);
            }, function() {
                loadPdfs();
                alert(timeoutMessage);
            });
            return;
        }

        // Show elapsed seconds so the user knows it's still working
        var elapsed = Math.round((attempt * POLL_MS) / 1000);
        var dots    = '.'.repeat((attempt % 3) + 1);
        btn.text('⏳ ' + (L.btn_processing || 'Processing') + dots + ' ' + elapsed + 's');

        setTimeout(function() {
            ajax('tsoimma_pdf_status', { attachment_id: id, replace: replace }, function(data) {
                if (data.status === 'done') {
                    // Application error (no_gain, corrupt PDF, replace failed)
                    if (data.error) {
                        btn.prop('disabled', false).text(uiText('pdf_compress_btn', '📄 Compress')).css('background', '');
                        alert(data.error);
                        loadPdfs();
                        return;
                    }
                    var r   = data.result || {};
                    var pct = (r.savings_pct != null) ? parseFloat(r.savings_pct).toFixed(1) : '?';
                    btn.prop('disabled', false)
                       .text('✓ ' + pct + '% ' + (L.optimize_done || 'saved'))
                       .css('background', 'var(--imp-success)');
                    if (r.new_size && $sizeCell && $sizeCell.length) $sizeCell.text(formatBytes(r.new_size));
                } else if (data.status === 'idle') {
                    btn.prop('disabled', false).text(uiText('pdf_compress_btn', '📄 Compress')).css('background', '');
                    alert(L.gs_none || 'GhostScript failed silently.');
                    loadPdfs();
                } else {
                    pollPdfStatus(id, btn, $sizeCell, attempt + 1, replace);
                }
            }, function() { pollPdfStatus(id, btn, $sizeCell, attempt + 1, replace); });
        }, POLL_MS);
    }

    function pollPdfStatusBulk(id, onDone, attempt) {
        var POLL_MS = 1500;
        var MAX_ATTEMPTS = 80; // ~= 120s
        attempt = attempt || 0;
        if (attempt > MAX_ATTEMPTS) { onDone(null); return; }
        setTimeout(function() {
            ajax('tsoimma_pdf_status', { attachment_id: id, replace: 1 }, function(data) {
                if (data.status === 'done')       onDone(data.result || {});
                else if (data.status === 'idle')  onDone(null);
                else                              pollPdfStatusBulk(id, onDone, attempt + 1);
            }, function() { pollPdfStatusBulk(id, onDone, attempt + 1); });
        }, POLL_MS);
    }

    function initPdfTab() {
        $(document).on('click', '.imp-tab[data-tab="pdf"]', function() { loadPdfs(); });
        initPdfPreview();
        var pdfSearchTimer;
        $('#imp-search-pdf').on('input', function() {
            clearTimeout(pdfSearchTimer);
            var v = $(this).val();
            pdfSearchTimer = setTimeout(function() { pdfState.search = v; pdfState.page = 1; loadPdfs(); }, 400);
        });
        $('#imp-pdf-select-all').on('click', function() {
            $('#imp-pdf-grid .imp-pdf-row').each(function() { pdfState.selected.add($(this).data('id')); $(this).addClass('selected'); });
            updatePdfCount();
        });
        $('#imp-pdf-deselect').on('click', function() {
            pdfState.selected.clear();
            $('#imp-pdf-grid .imp-pdf-row').removeClass('selected');
            updatePdfCount();
        });
        $(document).on('click', '#imp-pdf-grid .imp-pdf-row', function(e) {
            if ($(e.target).closest('.imp-pdf-compress-btn, .imp-pdf-preview-btn').length) return;
            var id = $(this).data('id');
            if (pdfState.selected.has(id)) { pdfState.selected.delete(id); $(this).removeClass('selected'); }
            else { pdfState.selected.add(id); $(this).addClass('selected'); }
            updatePdfCount();
        });
        $(document).on('click', '.imp-pdf-compress-btn', function(e) {
            e.stopPropagation();
            var id  = $(this).data('id');
            var btn = $(this);
            btn.prop('disabled', true).text('⏳');
            ajax('tsoimma_compress_pdf', {
                attachment_id: id,
                quality: $('#imp-pdf-quality').val(),
                replace: $('#imp-pdf-replace').is(':checked') ? 1 : 0
            }, function(data) {
                if (data.status === 'processing') {
                    btn.text(L.btn_processing || '⏳ Processing...');
                    var replace = $('#imp-pdf-replace').is(':checked') ? 1 : 0;
                    pollPdfStatus(id, btn, btn.closest('.imp-pdf-row').find('.imp-pdf-size'), 0, replace);
                }
            }, function(err) {
                btn.prop('disabled', false).text(uiText('pdf_compress_btn', '📄 Compress')).css('background', '');
                alert(err || (L.error_prefix || 'Error: ') + ' PDF compression failed.');
                // Refresh list so newly marked "non-compressible" items appear immediately.
                loadPdfs();
            });
        });
        $('#imp-bulk-compress-pdf').on('click', function() {
            var ids = Array.from(pdfState.selected);
            if (!ids.length) { alert(L.no_selection || 'Select at least one.'); return; }
            bulkCompressPdf(ids);
        });
    }

    function initPdfPreview() {
        var $modal = $('#imp-pdf-preview-modal');
        function closePdfPreview() {
            $modal.hide();
            $('#imp-pdf-preview-frame').attr('src', 'about:blank');
        }
        function openPdfPreview(url, filename) {
            if (!url) return;
            $('#imp-pdf-preview-title').text(filename || uiText('pdf_preview_title', 'PDF preview'));
            $('#imp-pdf-preview-open').attr('href', url).text(uiText('pdf_open_tab', 'Open in new tab'));
            $('#imp-pdf-preview-frame').attr('src', url);
            $modal.show();
        }
        $(document).on('click', '.imp-pdf-preview-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openPdfPreview($(this).attr('data-url') || '', $(this).attr('data-filename') || '');
        });
        $modal.on('click', '.imp-modal-overlay, .imp-pdf-preview-close', function(e) {
            e.preventDefault();
            closePdfPreview();
        });
        $(document).on('keydown.impPdfPreview', function(e) {
            if (e.key === 'Escape' && $modal.is(':visible')) closePdfPreview();
        });
    }

    function loadPdfs() {
        var grid = $('#imp-pdf-grid');
        grid.html('<div class="imp-loading">' + (L.loading_pdfs || 'Loading PDFs...') + '</div>');
        ajax('tsoimma_get_pdfs', { page: pdfState.page, per_page: pdfState.perPage, search: pdfState.search }, function(data) {
            var $eng = $('#imp-pdf-engine-status');
            if (data.gs_available) {
                $eng.html('<span class="imp-pdf-engine-ok">✓ ' + (L.gs_available || 'GhostScript available') + '</span>');
            } else if (data.imagick_available) {
                $eng.html('<span class="imp-pdf-engine-warn">' + (L.pdf_engine_warn || '⚠ GhostScript not found. Using Imagick.') + '</span>');
            } else {
                $eng.html('<span class="imp-pdf-engine-err">✗ ' + (L.gs_none || 'No engine available.') + '</span>');
            }
            renderPdfGrid(data.items);
            $('#imp-pdf-count').text(data.total + ' PDFs');
        });
    }

    function renderPdfGrid(items) {
        var grid = $('#imp-pdf-grid');
        grid.empty();
        if (!items.length) { grid.html('<div class="imp-loading">' + (L.no_pdfs || 'No PDFs found.') + '</div>'); return; }
        items.forEach(function(item) {
            var compTag = item.compressed ? '<span class="imp-pdf-compressed">✓ ' + uiText('compressed_tag', 'Compressed') + '</span>' : '';
            var noCompTag = item.non_compressible ? '<span class="imp-pdf-compressed" style="background:rgba(239,68,68,.18);color:#fecaca;">⚠ ' + uiText('non_compressible_tag', 'Not compressible') + '</span>' : '';
            var reasonTitle = item.non_compressible_reason ? (' title="' + escHtml(item.non_compressible_reason) + '"') : '';
            var btnDisabled = item.non_compressible ? ' disabled' : '';
            var row = $('<div class="imp-pdf-row" data-id="' + item.id + '"></div>');
            row.append('<span class="imp-pdf-icon">📄</span>');
            row.append('<span class="imp-pdf-name" title="' + escHtml(item.filename) + '">' + escHtml(item.filename) + '</span>');
            row.append('<span class="imp-pdf-size">' + escHtml(item.filesize) + '</span>');
            row.append('<span class="imp-pdf-date">' + escHtml(item.date) + '</span>');
            if (compTag) row.append(compTag);
            if (noCompTag) row.append(noCompTag);
            if (item.url) {
                row.append(
                    '<button type="button" class="imp-pdf-preview-btn" data-id="' + item.id + '" data-url="' + escHtml(item.url) + '" data-filename="' + escHtml(item.filename) + '" title="' + escHtml(uiText('pdf_preview_btn', 'View')) + '">' + uiText('pdf_preview_btn', '👁 View') + '</button>'
                );
            }
            row.append('<button class="imp-pdf-compress-btn" data-id="' + item.id + '"' + btnDisabled + reasonTitle + '>' + uiText('pdf_compress_btn', '📄 Compress') + '</button>');
            grid.append(row);
        });
    }

    function bulkCompressPdf(ids) {
        var total = ids.length;
        var done  = 0;
        $('#imp-pdf-bulk-progress').show();
        $('#imp-bulk-compress-pdf').prop('disabled', true);
        function next() {
            if (done >= total) { $('#imp-bulk-compress-pdf').prop('disabled', false); loadPdfs(); return; }
            var id = ids[done];
            ajax('tsoimma_compress_pdf', { attachment_id: id, quality: $('#imp-pdf-quality').val(), replace: 1 }, function(data) {
                if (data.status === 'processing') {
                    pollPdfStatusBulk(id, function(result) {
                        done++;
                        if (result) {
                            $('#imp-pdf-progress-fill').css('width', Math.round(done/total*100) + '%');
                            $('#imp-pdf-progress-text').text((L.bulk_processing || 'Processed') + ' ' + done + '/' + total);
                        }
                        next();
                    });
                } else { done++; next(); }
            }, function() { done++; next(); });
        }
        next();
    }

    function updatePdfCount() {
        $('#imp-pdf-count').text(pdfState.selected.size + ' ' + (L.n_selected || 'selected'));
        $('#imp-bulk-compress-pdf').prop('disabled', pdfState.selected.size === 0);
    }

    // ================================================================
    // AUTO-OPTIMIZATION TAB
    // ================================================================
    function initAutoTab() {
        $('#imp-fix-orphan-meta').on('click', function() {
            var $btn = $(this);
            var $res = $('#imp-fix-orphan-result');
            $btn.prop('disabled', true).text(L.btn_repairing || '⏳ Repairing...');
            $res.hide();
            ajax('tsoimma_fix_orphan_meta', {}, function(data) {
                $btn.prop('disabled', false).text(uiText('repair_paths', '🔧 Repair broken paths'));
                $res.data('kind', 'repaired').data('count', (data.fixed || 0));
                $res.show().css('color', 'var(--imp-success)').html('✓ ' + (data.fixed || 0) + ' ' + uiText('repaired_msg', 'images repaired.'));
            }, function(err) {
                $btn.prop('disabled', false).text(uiText('repair_paths', '🔧 Repair broken paths'));
                $res.removeData('kind').removeData('count');
                $res.show().css('color', 'var(--imp-danger)').text((L.error_prefix || 'Error: ') + err);
            });
        });
        $('#imp-auto-quality').on('input', function() { $('#imp-auto-quality-val').text($(this).val()); });
        $('#imp-auto-enabled').on('change', function() { updateAutoToggleUI($(this).is(':checked')); });
        $('#imp-save-auto').on('click', function() {
            var selectedSourceFormats = $('.imp-auto-src-format:checked').map(function() { return $(this).val(); }).get();
            ajax('tsoimma_save_auto_settings', {
                enabled: $('#imp-auto-enabled').is(':checked') ? 1 : 0,
                format:  $('#imp-auto-format').val(),
                quality: $('#imp-auto-quality').val(),
                source_formats: selectedSourceFormats,
                fill_alt_on_upload: $('#imp-auto-fill-alt').is(':checked') ? 1 : 0,
                skip_small_kb: $('#imp-auto-skip-kb').val()
            }, function(data) {
                $('#imp-auto-saved').show().delay(2000).fadeOut();
                updateAutoToggleUI(data.enabled);
                markDashboardDirty();
            });
        });
        // ── Fix mime type mismatch ────────────────────────────────
        $('#imp-fix-mime-mismatch').on('click', function() {
            var $btn = $(this);
            var $res = $('#imp-fix-mime-result');
            $btn.prop('disabled', true).text(L.btn_repairing || '⏳ Repairing...');
            $res.hide();
            ajax('tsoimma_fix_mime_mismatch', {}, function(data) {
                $btn.prop('disabled', false).text(uiText('mime_fix_btn', '🔧 Fix incorrect mime types'));
                var mimeMsg = data.fixed > 0
                    ? '✓ ' + data.fixed + ' ' + uiText('mime_fixed', 'attachments repaired.')
                    : '✓ ' + uiText('mime_no_issues', 'No issues found. All attachments are correct.');
                $res.data('kind', data.fixed > 0 ? 'mime_fixed' : 'mime_no_issues').data('count', (data.fixed || 0));
                $res.show().css('color', 'var(--imp-success)').text(mimeMsg);
            }, function(err) {
                $btn.prop('disabled', false).text(uiText('mime_fix_btn', '🔧 Fix incorrect mime types'));
                $res.removeData('kind').removeData('count');
                $res.show().css('color', 'var(--imp-danger)').text((L.error_prefix || 'Error: ') + err);
            });
        });

        // ── Ghost attachments ─────────────────────────────────────
        var ghostSelected = new Set();

        $('#imp-scan-ghosts').on('click', function() {
            var $btn = $(this);
            var $res = $('#imp-ghost-scan-result');
            $btn.prop('disabled', true).text(L.btn_scanning || '⏳ Scanning...');
            $res.hide();
            $('#imp-ghost-list').hide().empty();
            $('#imp-ghost-actions').hide();
            ghostSelected.clear();

            ajax('tsoimma_find_ghost_attachments', {}, function(data) {
                $btn.prop('disabled', false).text('🔍 ' + uiText('scan_ghosts', 'Scan ghost attachments'));
                if (!data.total) {
                    $res.data('kind', 'ghost_none').data('count', 0);
                    $res.show().css('color', 'var(--imp-success)').html('✓ ' + uiText('no_ghosts', 'No ghost attachments found.'));
                    return;
                }
                $res.data('kind', 'ghost_found').data('count', data.total);
                $res.show().css('color', 'var(--imp-warn)').text(data.total + ' ' + (data.total > 1 ? uiText('ghost_found_plural', 'ghost attachments found.') : uiText('ghost_found_singular', 'ghost attachment found.')));
                renderGhostList(data.ghosts);
                $('#imp-ghost-list').show();
                $('#imp-ghost-actions').css('display', 'flex');
            }, function(err) {
                $btn.prop('disabled', false).text('🔍 ' + uiText('scan_ghosts', 'Scan ghost attachments'));
                $res.removeData('kind').removeData('count');
                $res.show().css('color', 'var(--imp-danger)').text((L.error_prefix || 'Error: ') + err);
            });
        });

        function renderGhostList(ghosts) {
            var $list = $('#imp-ghost-list');
            $list.empty();
            ghosts.forEach(function(g) {
                var $row = $('<div class="imp-ghost-row" style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--imp-border);">' +
                    '<input type="checkbox" class="imp-ghost-check" data-id="' + g.id + '" style="cursor:pointer;">' +
                    '<span style="font-family:var(--imp-mono);font-size:12px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escHtml(g.meta_path) + '">' + escHtml(g.filename) + '</span>' +
                    '<span style="font-size:11px;color:var(--imp-danger);flex-shrink:0;">' + escHtml(g.reason) + '</span>' +
                    '</div>');
                $list.append($row);
            });

            // Checkbox toggle
            $list.off('change', '.imp-ghost-check').on('change', '.imp-ghost-check', function() {
                var id = parseInt($(this).data('id'), 10);
                if ($(this).is(':checked')) ghostSelected.add(id);
                else ghostSelected.delete(id);
                updateGhostToolbar();
            });
            updateGhostToolbar();
        }

        function updateGhostToolbar() {
            $('#imp-delete-ghosts').prop('disabled', ghostSelected.size === 0);
        }

        $('#imp-ghost-select-all').on('click', function() {
            $('#imp-ghost-list .imp-ghost-check').prop('checked', true).each(function() {
                ghostSelected.add(parseInt($(this).data('id'), 10));
            });
            updateGhostToolbar();
        });

        $('#imp-ghost-deselect').on('click', function() {
            $('#imp-ghost-list .imp-ghost-check').prop('checked', false);
            ghostSelected.clear();
            updateGhostToolbar();
        });

        $('#imp-delete-ghosts').on('click', function() {
            if (!ghostSelected.size) return;
            if (!confirm((L.confirm_delete_ghosts || 'Delete') + ' ' + ghostSelected.size + ' ' + (L.ghost_confirm_suffix || 'ghost attachment(s)? This removes the database records.'))) return;
            var $btn = $(this);
            var $res = $('#imp-ghost-delete-result');
            $btn.prop('disabled', true).text(L.btn_deleting || '⏳ Deleting...');
            $res.hide();
            ajax('tsoimma_delete_ghost_attachments', { ids: Array.from(ghostSelected) }, function(data) {
                $btn.prop('disabled', false).text('🗑 ' + uiText('delete_ghosts', 'Delete selected'));
                var msg = '✓ ' + data.deleted + ' ' + (L.ghost_deleted_ok || 'deleted successfully.');
                if (data.errors && data.errors.length) msg += ' ' + (L.errors_label || 'Errors:') + ' ' + data.errors.join(', ');
                $res.data('kind', 'ghost_deleted').data('count', data.deleted || 0);
                $res.show().css('color', data.errors && data.errors.length ? 'var(--imp-warn)' : 'var(--imp-success)').html(msg);

                // Only rows that were actually deleted should disappear — an
                // attempted-but-rejected row (e.g. "real file with content,
                // not deleted for safety") must stay visible, otherwise the
                // admin thinks it's handled when the DB record is untouched.
                // ghostSelected must be read BEFORE clearing it, and the
                // per-ID outcome inferred from the "ID <n>: ..." prefix each
                // backend error message uses (see handle_tso_im_delete_ghost_attachments()).
                var attemptedIds = Array.from(ghostSelected);
                var failedIds = {};
                (data.errors || []).forEach(function(errMsg) {
                    var m = /ID\s+(\d+)/.exec(errMsg);
                    if (m) failedIds[m[1]] = true;
                });
                ghostSelected.clear();
                $('#imp-ghost-list .imp-ghost-check').each(function() {
                    var id = parseInt($(this).data('id'), 10);
                    if (attemptedIds.indexOf(id) !== -1 && !failedIds[id]) {
                        $(this).closest('.imp-ghost-row').fadeOut(300, function() { $(this).remove(); });
                    }
                });
                $('#imp-ghost-scan-result').text((($('#imp-ghost-list .imp-ghost-row').length) - data.deleted) + ' ' + (L.remaining_label || 'remaining') + '.');
                updateGhostToolbar();
                markDashboardDirty();
            }, function(err) {
                $btn.prop('disabled', false).text('🗑 ' + uiText('delete_ghosts', 'Delete selected'));
                $res.show().css('color', 'var(--imp-danger)').text((L.error_prefix || 'Error: ') + err);
            });
        });
    }

    function loadAutoSettings() {
        ajax('tsoimma_get_auto_settings', {}, function(data) {
            $('#imp-auto-enabled').prop('checked', !!data.enabled);
            $('#imp-auto-format').val(data.format || 'webp').trigger('change');
            $('#imp-auto-quality').val(data.quality || 82);
            $('#imp-auto-quality-val').text(data.quality || 82);
            $('.imp-auto-src-format').prop('checked', false);
            (data.source_formats || ['jpg', 'png', 'webp']).forEach(function(fmt) {
                $('.imp-auto-src-format[value="' + fmt + '"]').prop('checked', true);
            });
            $('#imp-auto-fill-alt').prop('checked', !!data.fill_alt_on_upload);
            $('#imp-auto-skip-kb').val(data.skip_small_kb || 0);
            updateAutoToggleUI(!!data.enabled);
        });
    }

    function updateAutoToggleUI(enabled) {
        if (enabled) {
            $('#imp-auto-status-label').text(L.auto_enabled  || 'Auto-optimization ENABLED');
            $('#imp-auto-status-desc').text(L.auto_desc_enabled  || 'New images will be optimized automatically.');
        } else {
            $('#imp-auto-status-label').text(L.auto_disabled || 'Auto-optimization disabled');
            $('#imp-auto-status-desc').text(L.auto_desc_disabled || 'Enable to optimize automatically.');
        }
    }

    // ================================================================
    // HISTORY TAB
    // ================================================================
    var histState = { page: 1, perPage: 50 };

    function initHistoryTab() {
        $('#imp-history-date-from').val('');
        $('#imp-history-date-to').val('');
        loadHistoryRetention();
        $('#imp-history-retention-save').on('click', function() {
            var $err = $('#imp-retention-error');
            $err.hide().text('');
            ajax('tsoimma_save_history_retention', {
                days: $('#imp-history-retention-days').val(),
                interval: $('#imp-history-purge-interval').val()
            }, function(data) {
                applyHistoryRetention(data);
                $('#imp-retention-saved').text(uiText('retention_saved', 'Saved!')).show().delay(2000).fadeOut();
            }, function(err) {
                $err.text(err || uiText('retention_invalid', 'Invalid value.')).show();
            });
        });
        $(document).on('click', '.imp-tab[data-tab="history"]', function() {
            loadHistoryStats('#imp-history-stats');
            histState.page = 1;
            loadHistory();
        });
        $('#imp-history-load').on('click', function() { histState.page = 1; loadHistory(); });
        $('#imp-history-clear-30').on('click', function() {
            if (!confirm(L.confirm_clean_30 || 'Delete entries older than 30 days?')) return;
            ajax('tsoimma_clear_history', { days: 30 }, function() { loadHistory(); loadHistoryStats('#imp-history-stats'); });
        });
        $('#imp-history-clear-all').on('click', function() {
            if (!confirm(L.confirm_clean_all || 'Delete ALL history?')) return;
            ajax('tsoimma_clear_history', { days: 0 }, function() {
                $('#imp-history-table-wrap').html('<div class="imp-loading">' + (L.history_empty || 'History empty.') + '</div>');
                $('#imp-history-pagination').empty();
                loadHistoryStats('#imp-history-stats');
            });
        });
    }

    function applyHistoryRetention(data) {
        if (!data) return;
        if (typeof data.days !== 'undefined') {
            $('#imp-history-retention-days').val(data.days);
        }
        if (data.interval) {
            var $interval = $('#imp-history-purge-interval');
            $interval.val(data.interval).trigger('change');
            var api = $interval.closest('.imp-csel').data('impCselApi');
            if (api && api.sync) api.sync();
        }
    }

    function loadHistoryRetention() {
        ajax('tsoimma_get_history_retention', {}, function(data) {
            applyHistoryRetention(data);
        });
    }

    function loadHistory() {
        var wrap = $('#imp-history-table-wrap');
        wrap.html('<div class="imp-loading">' + (L.loading_data || 'Loading...') + '</div>');
        ajax('tsoimma_get_history', {
            page: histState.page, per_page: histState.perPage,
            action_type: $('#imp-history-filter-type').val(),
            search:      $('#imp-history-search').val(),
            date_from:   $('#imp-history-date-from').val(),
            date_to:     $('#imp-history-date-to').val()
        }, function(data) {
            renderHistoryTable(data.items);
            renderPagination('#imp-history-pagination', data.page, data.total_pages, function(p) { histState.page = p; loadHistory(); });
        }, function(err) { wrap.html('<div class="imp-loading">' + (L.error_prefix || 'Error: ') + escHtml(err) + '</div>'); });
    }

    function renderHistoryTable(items) {
        var wrap = $('#imp-history-table-wrap');
        if (!items.length) { wrap.html('<div class="imp-loading">' + (L.no_history || 'No entries found.') + '</div>'); return; }
        var html = '<div class="imp-history-table-container"><table class="imp-history-table"><thead><tr><th>' + uiText('hdr_image', 'Image') + '</th><th>' + uiText('hdr_file', 'File') + '</th><th>' + uiText('hdr_action', 'Action') + '</th><th>' + uiText('hdr_details', 'Details') + '</th><th>' + uiText('hdr_user', 'User') + '</th><th>' + uiText('hdr_date', 'Date') + '</th></tr></thead><tbody>';
        items.forEach(function(item) {
            var actionLabelMap = {
                optimize: uiText('action_optimize', 'Optimized'),
                auto_optimize: uiText('action_auto_optimize', 'Auto-optimized'),
                rename: uiText('action_rename', 'Renamed'),
                seo_update: uiText('action_seo_update', 'SEO updated'),
                delete: uiText('action_delete', 'Deleted'),
                pdf_compress: uiText('action_pdf_compress', 'PDF compressed'),
                revert: uiText('action_revert', 'Reverted'),
                dup_merge_rewrite: uiText('action_dup_merge_rewrite', 'Merge: repointed'),
                dup_merge_delete: uiText('action_dup_merge_delete', 'Merge: deleted'),
                dup_delete_unused: uiText('action_dup_delete_unused', 'Duplicate deleted'),
                dup_detach: uiText('action_dup_detach', 'Unlinked'),
                size_deleted: uiText('action_size_deleted', 'Size deleted')
            };
            var d    = item.details || {};
            var details = '';
            if (d.savings_pct)  details += '<span class="imp-history-savings">-' + d.savings_pct + '%</span> (' + formatBytes(d.savings_bytes || 0) + ')';
            if (d.old_filename && d.new_filename) details += '<span style="color:var(--imp-text-muted);display:block;font-size:11px">' + escHtml(d.old_filename) + '</span><span style="color:var(--imp-accent2);display:block;font-size:11px">\u2192 ' + escHtml(d.new_filename) + '</span>';
            // SEO fields (seo_* keys; legacy alt/caption/description only — never auto-filled title).
            var seoAlt = d.seo_alt || d.alt || '';
            var seoTitle = (typeof d.seo_title === 'string') ? d.seo_title : '';
            var seoCaption = d.seo_caption || d.caption || '';
            var seoDesc = d.seo_description || d.description || '';
            if (item.action_type === 'seo_update' || seoAlt || seoTitle || seoCaption || seoDesc) {
                var seoBits = [];
                if (seoTitle) seoBits.push('<strong>' + escHtml(uiText('seo_title_label', 'Title')) + ':</strong> ' + escHtml(seoTitle));
                if (seoAlt) seoBits.push('<strong>' + escHtml(uiText('seo_alt_label', 'Alt')) + ':</strong> ' + escHtml(seoAlt));
                if (seoCaption) seoBits.push('<strong>' + escHtml(uiText('seo_caption_label', 'Caption')) + ':</strong> ' + escHtml(seoCaption));
                if (seoDesc) seoBits.push('<strong>' + escHtml(uiText('seo_desc_label', 'Description')) + ':</strong> ' + escHtml(String(seoDesc).slice(0, 120)));
                if (d.source === 'dashboard_bulk_alt') seoBits.push('<span style="color:var(--imp-text-muted)">' + escHtml(uiText('hist_seo_bulk', 'Bulk fill')) + '</span>');
                if (d.source === 'dashboard_inline_alt') seoBits.push('<span style="color:var(--imp-text-muted)">' + escHtml(uiText('hist_seo_dashboard', 'Dashboard')) + '</span>');
                if (d.source === 'auto_upload_alt') seoBits.push('<span style="color:var(--imp-text-muted)">' + escHtml(uiText('hist_seo_auto', 'On upload')) + '</span>');
                if (seoBits.length) {
                    details += '<span style="display:block;font-size:11px;line-height:1.4">' + seoBits.join('<br>') + '</span>';
                }
            }
            if (d.format && (item.action_type === 'optimize' || item.action_type === 'auto_optimize' || item.action_type === 'pdf_compress')) {
                if (details) details += ' · ';
                details += '<span style="font-size:11px;color:var(--imp-text-muted)">' + escHtml(String(d.format).toUpperCase()) + '</span>';
            }
            if (item.action_type === 'dup_merge_rewrite') {
                var rewriteBits = [];
                if (d.post_title) rewriteBits.push('<strong>' + escHtml(d.post_title) + '</strong>' + (d.post_id ? ' #' + d.post_id : ''));
                if (d.detail) rewriteBits.push(escHtml(d.detail));
                rewriteBits.push(escHtml(uiText('hist_merged_into', 'Merged into')) + ' ' + escHtml(d.keep_filename || ('#' + d.keep_id)));
                details += '<span style="display:block;font-size:11px;line-height:1.4">' + rewriteBits.join(' · ') + '</span>';
            } else if (item.action_type === 'dup_merge_delete') {
                details += '<span style="display:block;font-size:11px;line-height:1.4">' +
                    escHtml(uiText('hist_replaced_by', 'Replaced by')) + ' ' + escHtml(d.keep_filename || ('#' + d.keep_id)) +
                    '</span>';
            } else if (item.action_type === 'dup_delete_unused') {
                details += '<span style="font-size:11px;color:var(--imp-text-muted)">' + escHtml(uiText('hist_no_refs_found', 'No references found')) + '</span>';
            } else if (item.action_type === 'dup_detach') {
                details += '<span style="font-size:11px;color:var(--imp-text-muted)">' +
                    escHtml(uiText('hist_detached_from', 'Unlinked from')) + ' ' + escHtml(d.old_parent_title || ('#' + d.old_parent_id)) +
                    '</span>';
            } else if (item.action_type === 'size_deleted') {
                details += '<span style="font-size:11px;color:var(--imp-text-muted)">' +
                    escHtml(uiText('hist_size_deleted_detail', 'Size')) + ': ' + escHtml(d.size || '') +
                    (d.bytes ? ' · ' + formatBytes(d.bytes) : '') +
                    '</span>';
            }
            if (!details) details = '<span style="color:var(--imp-text-muted)">—</span>';
            var displayFile = d.filename || (d.new_filename || '—');
            var thumb = item.thumb
                ? '<img class="imp-thumb-sm" src="' + escHtml(item.thumb) + '" data-full-url="' + escHtml(item.full_url || item.thumb) + '" data-filename="' + escHtml(displayFile !== '—' ? displayFile : '') + '" alt="">'
                : '<span style="font-size:20px">📄</span>';
            var actionLabel = actionLabelMap[item.action_type] || item.action_label || item.action_type || '—';
            var userLabel = (item.user_name === 'Sistema') ? uiText('system_user', 'System') : (item.user_name || '');
            html += '<tr><td>' + thumb + '</td><td style="font-family:var(--imp-mono);font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escHtml(displayFile) + '">' + escHtml(displayFile) + '</td><td><span class="imp-history-action ' + escHtml(item.action_type) + '">' + escHtml(actionLabel) + '</span></td><td style="max-width:220px">' + details + '</td><td style="font-size:12px;color:var(--imp-text-muted)">' + escHtml(userLabel) + '</td><td style="font-size:12px;color:var(--imp-text-muted);white-space:nowrap">' + escHtml(item.created_at_h) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        wrap.html(html);
    }

    function loadHistoryStats(selector) {
        ajax('tsoimma_get_history_stats', {}, function(data) {
            var $el  = $(selector);
            var html = '<div class="imp-stat-card"><span class="imp-stat-label">' + uiText('stat_total', 'Total operations') + '</span><span class="imp-stat-val">' + data.total_operations + '</span></div>' +
                       '<div class="imp-stat-card"><span class="imp-stat-label">' + uiText('stat_saved', 'Space freed') + '</span><span class="imp-stat-val" style="color:var(--imp-success)">' + data.total_saved_h + '</span></div>';
            var types = {
                optimize: uiText('filter_optimize', '⚡ Optimized'),
                auto_optimize: uiText('filter_auto', '🤖 Auto-opt.'),
                rename: uiText('filter_rename', '✏ Renamed'),
                seo_update: uiText('filter_seo', '🏷 SEO'),
                pdf_compress: uiText('filter_pdf', '📄 PDFs')
            };
            Object.keys(types).forEach(function(key) {
                var cnt = (data.by_type || {})[key] || 0;
                html += '<div class="imp-stat-card"><span class="imp-stat-label">' + types[key] + '</span><span class="imp-stat-val">' + cnt + '</span></div>';
            });
            $el.html(html);
        });
    }

    // ================================================================
    // URL FIXER TAB
    // ================================================================
    function initUrlFixer() {
        var urlIssues       = [];
        var urlSelected     = new Set();
        var urlRemoveSelected = new Set();

        function renderUrlSummary(summary) {
            if (!summary) return;
            var summHtml = '<div class="imp-stat-box"><span class="imp-stat-label">' + uiText('posts_scanned', 'Posts scanned') + '</span><span class="imp-stat-val">' + summary.total_posts_scanned + '</span></div>' +
                           '<div class="imp-stat-box"><span class="imp-stat-label">' + uiText('broken_urls', 'Broken URLs') + '</span><span class="imp-stat-val" style="color:var(--imp-danger)">' + summary.total + '</span></div>' +
                           '<div class="imp-stat-box"><span class="imp-stat-label">' + uiText('fixable_urls', 'Auto-fixable') + '</span><span class="imp-stat-val" style="color:var(--imp-success)">' + summary.fixable + '</span></div>' +
                           '<div class="imp-stat-box"><span class="imp-stat-label">' + uiText('removable_label', 'removable') + '</span><span class="imp-stat-val" style="color:var(--imp-warn)">' + (summary.removable || 0) + '</span></div>';
            $('#imp-url-summary').html(summHtml);
        }

        refreshUrlFixerUi = function() {
            if (!$('#tab-urlfixer').hasClass('active') || !$('#imp-url-result').is(':visible')) return;
            renderUrlSummary($('#imp-url-summary').data('summary'));
            if (!urlIssues.length) {
                $('#imp-url-toolbar').hide();
                $('#imp-url-list').html('<div class="imp-loading" style="padding:40px 0">✓ ' + uiText('url_all_ok', 'No broken URLs found!') + '</div>');
                return;
            }
            $('#imp-url-toolbar').show();
            renderUrlList();
            updateUrlToolbar();
        };

        $('#imp-scan-urls').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).text(uiText('btn_scanning', '⏳ Scanning...'));
            $('#imp-url-result').hide();
            $('#imp-url-loading').show();
            ajax('tsoimma_scan_url_issues', {}, function(data) {
                btn.prop('disabled', false).text(uiText('scan_web', '🔍 Scan entire site'));
                $('#imp-url-loading').hide();
                urlIssues    = data.issues || [];
                urlSelected  = new Set();
                urlRemoveSelected = new Set();
                $('#imp-url-summary').data('summary', {
                    total_posts_scanned: data.total_posts_scanned || 0,
                    total: data.total || 0,
                    fixable: data.fixable || 0,
                    removable: data.removable || 0
                });
                renderUrlSummary($('#imp-url-summary').data('summary'));
                $('#imp-url-result').show();
                if (!urlIssues.length) {
                    $('#imp-url-toolbar').hide();
                    $('#imp-url-list').html('<div class="imp-loading" style="padding:40px 0">✓ ' + uiText('url_all_ok', 'No broken URLs found!') + '</div>');
                    return;
                }
                $('#imp-url-toolbar').show();
                renderUrlList();
                updateUrlToolbar();
            }, function(err) {
                btn.prop('disabled', false).text(uiText('scan_web', '🔍 Scan entire site'));
                $('#imp-url-loading').hide();
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        $('#imp-url-select-all').on('click', function() {
            urlSelected = new Set();
            urlIssues.forEach(function(issue) { if (issue.has_fix) urlSelected.add(issue.old_url); });
            $('.imp-url-row.fixable').addClass('selected');
            updateUrlToolbar();
        });
        $('#imp-url-select-removable').on('click', function() {
            urlRemoveSelected = new Set();
            urlIssues.forEach(function(issue) { if (!issue.has_fix) urlRemoveSelected.add(issue.old_url); });
            $('.imp-url-row.removable').addClass('selected');
            updateUrlToolbar();
        });
        $('#imp-url-deselect').on('click', function() {
            urlSelected = new Set();
            urlRemoveSelected = new Set();
            $('.imp-url-row').removeClass('selected');
            updateUrlToolbar();
        });
        $(document).on('click', '.imp-url-row.fixable', function() {
            var url = $(this).data('url');
            if (urlSelected.has(url)) { urlSelected.delete(url); $(this).removeClass('selected'); }
            else                      { urlSelected.add(url);    $(this).addClass('selected');    }
            updateUrlToolbar();
        });
        $(document).on('click', '.imp-url-row.removable', function() {
            var url = $(this).data('url');
            if (urlRemoveSelected.has(url)) { urlRemoveSelected.delete(url); $(this).removeClass('selected'); }
            else                            { urlRemoveSelected.add(url);    $(this).addClass('selected');    }
            updateUrlToolbar();
        });
        $('#imp-fix-urls').on('click', function() {
            if (!urlSelected.size) return;
            if (!confirm(uiText('fix_selected', 'Fix') + ' ' + urlSelected.size + ' ' + uiText('url_items_suffix', 'URL(s)?'))) return;
            var btn = $(this);
            btn.prop('disabled', true).text(uiText('url_fixing', '⏳ Fixing...'));
            var fixes = [];
            urlIssues.forEach(function(issue) {
                if (urlSelected.has(issue.old_url) && issue.has_fix) fixes.push({ old_url: issue.old_url, new_url: issue.new_url });
            });
            ajax('tsoimma_fix_url_issues', { fixes: fixes }, function(data) {
                btn.prop('disabled', false).text(uiText('fix_selected', '✓ Fix selected'));
                var msg = '✓ ' + data.fixed + ' ' + uiText('url_fixed_ok', 'URLs fixed.');
                if (data.skipped) msg += ' (' + data.skipped + ' ' + uiText('skipped_suffix', 'skipped') + ')';
                if (data.errors && data.errors.length) msg += '\n' + uiText('errors_label', 'Errors:') + '\n' + data.errors.join('\n');
                alert(msg);
                // Only drop rows the backend actually fixed — a row that was
                // selected but skipped (e.g. the target file went missing
                // meanwhile) must stay in the list, otherwise the admin
                // thinks it's solved when the content still points nowhere.
                var fixedUrls = data.fixed_urls || [];
                urlIssues    = urlIssues.filter(function(i) { return fixedUrls.indexOf(i.old_url) === -1; });
                urlSelected  = new Set();
                renderUrlList();
                updateUrlToolbar();
                if (!urlIssues.length) {
                    $('#imp-url-toolbar').hide();
                    $('#imp-url-list').html('<div class="imp-loading" style="padding:40px 0">✓ ' + uiText('url_all_ok', 'All URLs fixed!') + '</div>');
                }
            }, function(err) { btn.prop('disabled', false).text(uiText('fix_selected', '✓ Fix selected')); alert((L.error_prefix || 'Error: ') + err); });
        });

        $('#imp-remove-urls').on('click', function() {
            if (!urlRemoveSelected.size) return;
            if (!confirm(uiText('confirm_remove_urls', 'Remove selected broken URL references from content?'))) return;
            var btn = $(this);
            btn.prop('disabled', true).text(uiText('url_removing', '⏳ Removing...'));
            var urls = Array.from(urlRemoveSelected);
            ajax('tsoimma_remove_url_issues', { urls: urls }, function(data) {
                btn.prop('disabled', false).text(uiText('remove_selected', '🗑 Remove from content'));
                var msg = '✓ ' + data.removed + ' ' + uiText('url_removed_ok', 'URL references removed.');
                if (data.skipped) msg += ' (' + data.skipped + ' ' + uiText('skipped_suffix', 'skipped') + ')';
                if (data.errors && data.errors.length) msg += '\n' + uiText('errors_label', 'Errors:') + '\n' + data.errors.join('\n');
                alert(msg);
                // Same reasoning as the "Fix selected" handler above: only
                // remove rows the backend actually cleared from storage.
                var removedUrls = data.removed_urls || [];
                urlIssues = urlIssues.filter(function(i) { return removedUrls.indexOf(i.old_url) === -1; });
                urlRemoveSelected = new Set();
                var summary = $('#imp-url-summary').data('summary') || {};
                summary.total = urlIssues.length;
                summary.fixable = urlIssues.filter(function(i) { return i.has_fix; }).length;
                summary.removable = urlIssues.filter(function(i) { return !i.has_fix; }).length;
                $('#imp-url-summary').data('summary', summary);
                renderUrlSummary(summary);
                renderUrlList();
                updateUrlToolbar();
                if (!urlIssues.length) {
                    $('#imp-url-toolbar').hide();
                    $('#imp-url-list').html('<div class="imp-loading" style="padding:40px 0">✓ ' + uiText('url_all_ok', 'All URLs fixed!') + '</div>');
                }
            }, function(err) {
                btn.prop('disabled', false).text(uiText('remove_selected', '🗑 Remove from content'));
                alert((L.error_prefix || 'Error: ') + err);
            });
        });

        function renderUrlList() {
            var html = '';
            urlIssues.forEach(function(issue) {
                var fixable  = issue.has_fix;
                var selClass = (fixable ? urlSelected.has(issue.old_url) : urlRemoveSelected.has(issue.old_url)) ? ' selected' : '';
                var cls      = fixable ? ' fixable' + selClass : ' removable no-fix' + selClass;
                var badge = issue.type === 'outdated'
                    ? '<span class="imp-url-badge imp-url-badge-outdated">🔄 ' + uiText('url_outdated_badge', 'Obsolete thumbnail') + '</span>'
                    : (issue.type === 'missing'
                        ? '<span class="imp-url-badge imp-url-badge-fix">⚠️ ' + uiText('url_missing_badge', 'Missing file \u2014 alternative found') + '</span>'
                        : '<span class="imp-url-badge imp-url-badge-broken">❌ ' + uiText('url_broken_badge', 'Missing \u2014 no fix') + '</span>');
                var postsHtml = issue.posts.map(function(p) {
                    var editUrl = TSOIMMA.site_url + '/wp-admin/post.php?post=' + p.id + '&action=edit';
                    return '<a href="' + escHtml(editUrl) + '" target="_blank" rel="noopener" class="imp-url-post-link">' + escHtml(p.title || (uiText('post_label', 'Post') + ' #' + p.id)) + '</a>';
                }).join('');
                html += '<div class="imp-url-row' + cls + '" data-url="' + escHtml(issue.old_url) + '">' +
                    '<div class="imp-url-row-header">' + badge + '<span class="imp-url-occ">' + issue.occurrences + ' ' + (issue.occurrences > 1 ? uiText('occurrences', 'occurrences') : uiText('occurrence', 'occurrence')) + '</span></div>' +
                    '<div class="imp-url-paths">' +
                    '<div class="imp-url-path imp-url-path-bad"><span class="imp-url-ext-badge" style="background:rgba(255,77,109,.2);color:var(--imp-danger)">.' + escHtml(issue.old_ext) + '</span><span class="imp-url-fname">' + escHtml(issue.filename) + '.' + escHtml(issue.old_ext) + '</span><span class="imp-url-label">' + uiText('url_content_label', 'URL in content (obsolete)') + '</span></div>' +
                    (fixable
                        ? '<div class="imp-url-path imp-url-path-good"><span class="imp-url-ext-badge" style="background:rgba(6,214,160,.2);color:var(--imp-success)">.' + escHtml(issue.new_ext) + '</span><span class="imp-url-fname">' + escHtml(issue.new_filename || issue.filename) + '.' + escHtml(issue.new_ext) + '</span><span class="imp-url-label">' + uiText('url_correct_label', 'Correct URL (file exists)') + '</span></div>'
                        : '<div class="imp-url-path imp-url-path-nofix"><span class="imp-url-label" style="color:var(--imp-text-muted)">' + uiText('url_no_fix_label', 'No alternative found.') + '</span></div>') +
                    '</div>' +
                    '<div class="imp-url-posts">' + uiText('appears_in', 'Appears in') + ': ' + postsHtml + '</div>' +
                    (fixable
                        ? '<div class="imp-url-select-hint">👆 ' + uiText('url_click_select', 'Click to select') + '</div>'
                        : '<div class="imp-url-select-hint">👆 ' + uiText('url_click_select_remove', 'Click to select for removal') + '</div>') +
                    '</div>';
            });
            $('#imp-url-list').html(html || '<div class="imp-loading">' + uiText('url_no_results', 'No results.') + '</div>');
        }

        function updateUrlToolbar() {
            var fixableCount   = urlIssues.filter(function(i) { return i.has_fix; }).length;
            var removableCount = urlIssues.filter(function(i) { return !i.has_fix; }).length;
            var selFix         = urlSelected.size;
            var selRemove      = urlRemoveSelected.size;
            $('#imp-url-count').text(
                selFix + ' ' + uiText('selected_of', 'selected of') + ' ' + fixableCount + ' ' + uiText('fixable_label', 'fixable') +
                ' · ' + selRemove + ' ' + uiText('selected_of', 'selected of') + ' ' + removableCount + ' ' + uiText('removable_label', 'removable')
            );
            $('#imp-fix-urls').prop('disabled', selFix === 0);
            $('#imp-remove-urls').prop('disabled', selRemove === 0);
        }
    }

})(jQuery);