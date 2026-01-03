<?php
/**
 * Shortcodes de la zona de socios en el frontend.
 *
 * Incluye tarjetas de ahorro, historial, perfil de beneficiario y formulario de registro
 * para nuevos socios del fondo.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Frontend_Shortcodes {

    /**
     * Registra todos los shortcodes disponibles en el frontend.
     */
    public function __construct() {
        add_shortcode( 'lud_resumen_ahorro', array( $this, 'render_resumen_ahorro' ) );
        add_shortcode( 'lud_historial', array( $this, 'render_historial_movimientos' ) );
        add_shortcode( 'lud_perfil_datos', array( $this, 'render_perfil_beneficiario' ) ); 
        add_action( 'admin_post_lud_guardar_perfil', array( $this, 'procesar_guardado_perfil' ) );
        // Ajax para historial paginado
        add_action( 'wp_ajax_lud_historial_mov', array( $this, 'ajax_historial_movimientos' ) );
        // --- NUEVOS SHORTCODES ---
        add_shortcode( 'lud_registro_socio', array( $this, 'render_formulario_registro' ) );
        add_action( 'admin_post_nopriv_lud_procesar_registro', array( $this, 'procesar_registro_nuevo' ) );
        add_action( 'admin_post_lud_procesar_registro', array( $this, 'procesar_registro_nuevo' ) );
    }

    // --- CARD 1: RESUMEN ---
    /**
     * Muestra la tarjeta de resumen de ahorro del socio.
     */
    public function render_resumen_ahorro() {
        if ( ! is_user_logged_in() ) return '<div class="lud-alert">Debes iniciar sesión.</div>';

        $user_id = get_current_user_id();
        global $wpdb;
        $datos = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );

        if ( ! $datos ) return '<div class="lud-card"><p>Cuenta no activa. Por favor actualiza tus datos de beneficiario para activar tu ficha.</p></div>';

        // Variables base
        $acciones = intval( $datos->numero_acciones );
        $valor_cuota_ahorro = $acciones * 50000;
        $valor_cuota_secretaria = $acciones * 1000;
        $valor_accion_unitario = ($acciones > 0) ? ($valor_cuota_ahorro / $acciones) : 50000; // Comentario: valor vigente de cada acción.
        
        // --- 1. CÁLCULO DEUDA MENSUAL AGRUPADA ---
        $info_deuda = LUD_Module_Transacciones::calcular_deuda_usuario_estatico( $user_id );
        $debe_ahorro = $info_deuda ? floatval( $info_deuda['ahorro'] ) : 0;
        $debe_secretaria = $info_deuda ? floatval( $info_deuda['secretaria'] ) : 0;
        $debe_multa = $info_deuda ? floatval( $info_deuda['multa'] ) : 0;
        $debe_interes_credito = $info_deuda ? floatval( $info_deuda['creditos_interes'] ) : 0;
        $debe_interes_mora = $info_deuda ? floatval( $info_deuda['creditos_mora'] ) : 0;
        $dias_mora_creditos = $info_deuda ? intval( $info_deuda['dias_mora_creditos'] ) : 0;
        $debe_otros = 0; // Comentario: valor reservado para cargos adicionales.

        // Comentario: meses calendario vencidos a partir del último aporte.
        $fecha_ultimo = $datos->fecha_ultimo_aporte ? $datos->fecha_ultimo_aporte : date('Y-m-01'); 
        $inicio = new DateTime( $fecha_ultimo );
        $inicio->modify( 'first day of next month' ); 
        $hoy = new DateTime();
        $meses_vencidos = 0;
        $dias_vencidos = 0; // Comentario: acumulador de días para generar textos de mora.
        $corte_mes = new DateTime( date('Y-m-01') ); // Comentario: corte al primer día del mes en curso.
        $cursor = clone $inicio;
        while ( $cursor <= $corte_mes ) {
            $meses_vencidos++;
            // Comentario: sumamos días del mes para describir mora en días cuando aplique.
            $dias_vencidos += intval( $cursor->format('t') );
            $cursor->modify( 'first day of next month' );
        }

        // Comentario: estimación de meses en mora a partir de los días calculados para multas.
        $dias_mora_estimados = ( $acciones > 0 ) ? $debe_multa / ( 1000 * $acciones ) : 0;
        $dias_mora_redondeados = ( $dias_mora_estimados > 0 ) ? ceil( $dias_mora_estimados ) : 0; // Comentario: días acumulados de mora para mostrar el cálculo exacto de la multa.

        $total_pendiente = $debe_ahorro + $debe_secretaria + $debe_multa + $debe_interes_credito + $debe_interes_mora + $debe_otros;

        // --- 2. CÁLCULO RENDIMIENTOS DINÁMICO ---
        $anio_actual = date('Y');
        $acumulado_este_anio = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(utilidad_asignada) FROM {$wpdb->prefix}fondo_utilidades_mensuales 
             WHERE user_id = %d AND anio = %d", 
            $user_id, $anio_actual
        ));
        
        $rendimientos_totales = floatval($datos->saldo_rendimientos) + floatval($acumulado_este_anio);
        $ahorro_total = number_format( $datos->saldo_ahorro_capital, 0, ',', '.' );

        // Créditos vigentes para mostrar tarjeta resumen si aplica.
        $creditos_vigentes = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE user_id = %d AND estado IN ('activo','mora','pendiente_tesoreria','fila_liquidez')",
            $user_id
        ) );
        $resumen_creditos = array();
        foreach ( $creditos_vigentes as $credito_vigente ) {
            $monto_base = $credito_vigente->monto_aprobado > 0 ? $credito_vigente->monto_aprobado : $credito_vigente->monto_solicitado;
            $cuota_base = $credito_vigente->cuota_estimada;
            $fecha_fin = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(fecha_vencimiento) FROM {$wpdb->prefix}fondo_amortizacion WHERE credito_id = %d",
                $credito_vigente->id
            ) );

            if ( ! $fecha_fin ) {
                $fecha_base = ! empty( $credito_vigente->fecha_aprobacion ) ? new DateTime( $credito_vigente->fecha_aprobacion ) : new DateTime( $credito_vigente->fecha_solicitud );
                $meses_plazo = max( 1, intval( $credito_vigente->plazo_meses ) );
                $fecha_base->modify( '+' . $meses_plazo . ' months' );
                $fecha_fin = $fecha_base->format( 'Y-m-d' );
            }

            $resumen_creditos[] = array(
                'id' => $credito_vigente->id,
                'monto' => $monto_base,
                'cuota' => $cuota_base,
                'fecha_fin' => $fecha_fin,
                'estado' => $credito_vigente->estado,
                'tipo' => $credito_vigente->tipo_credito
            );
        }
        
        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header">
                <h3>Mi Ahorro</h3>
                <span class="lud-badge <?php echo $total_pendiente > 0 ? 'pendiente' : 'aldia'; ?>">
                    <?php echo $total_pendiente > 0 ? 'En Mora' : 'Al día'; ?>
                </span>
            </div>

            <div class="lud-balance-section">
                <span class="lud-label">Total Ahorrado</span>
                <span class="lud-amount">$ <?php echo $ahorro_total; ?></span>
            </div>
            <p style="margin-top:8px; color:#666; font-size:0.9rem;">Activo desde: <?php echo $datos->fecha_ingreso_fondo ? date_i18n('d M Y', strtotime($datos->fecha_ingreso_fondo)) : 'Sin registro'; ?></p>

            <?php if ( $total_pendiente > 0 ): ?>
            <div class="lud-debt-box" style="border:none; padding:12px; background:#fff8e1; border-radius:12px; box-shadow:inset 0 0 0 1px #f1e0b3;">
                <div style="display:flex; justify-content:flex-start; align-items:center; margin-bottom:8px; gap:8px;">
                    <h4 style="margin:0; font-size:0.95rem; color:#b26a00;">⚠️ Pendientes por pagar</h4>
                </div>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php
                    $conceptos = array(
                        array(
                            'nombre' => 'Ahorro',
                            // Comentario: indicamos cuota mensual y días acumulados desde el último pago.
                            'detalle' => $meses_vencidos > 0
                                ? ('Cuota mensual $'.number_format($valor_cuota_ahorro).' · mora de '.number_format($dias_vencidos, 0, ',', '.').' día(s) acumulados')
                                : ('Cuota mensual $'.number_format($valor_cuota_ahorro).' pendiente'),
                            'subtotal' => $debe_ahorro
                        ),
                        array(
                            'nombre' => 'Secretaría',
                            'detalle' => $meses_vencidos > 0
                                ? ('Cuota mensual $'.number_format($valor_cuota_secretaria).' · mora de '.number_format($dias_vencidos, 0, ',', '.').' día(s) acumulados')
                                : ('Cuota mensual $'.number_format($valor_cuota_secretaria).' pendiente'),
                            'subtotal' => $debe_secretaria
                        ),
                        array(
                            'nombre' => 'Intereses Créditos',
                            'detalle' => 'Interés corriente (1.5% mensual) sobre el saldo activo; días en mora del crédito: '.number_format( $dias_mora_creditos, 0, ',', '.' ).'.',
                            'subtotal' => $debe_interes_credito
                        ),
                        array(
                            'nombre' => 'Intereses Mora',
                            'detalle' => $dias_mora_creditos > 0
                                ? ('Recargos de mora por '.number_format( $dias_mora_creditos, 0, ',', '.' ).' día(s) vencidos · 4% mensual prorrateado')
                                : 'Sin mora activa en créditos',
                            'subtotal' => $debe_interes_mora
                        ),
                        array(
                            'nombre' => 'Multas',
                            'detalle' => $dias_mora_redondeados > 0
                                ? ('Multas por '.number_format( $dias_mora_redondeados, 0, ',', '.' ).' día(s) de atraso acumulado · $1.000 por acción y día <span title=\"Se cobra $1.000 por cada acción y por cada día que pasa después del día 5 del mes sin pagar el ahorro. Se suma mes a mes hasta que se registre el pago.\">ℹ️</span>')
                                : 'Multas estatutarias pendientes',
                            'subtotal' => $debe_multa
                        ),
                        array(
                            'nombre' => 'Otros',
                            'detalle' => 'Ajustes o cargos especiales',
                            'subtotal' => $debe_otros
                        ),
                    );
                    foreach ( $conceptos as $con ):
                        if ( $con['subtotal'] <= 0 ) continue; // Comentario: ocultamos conceptos en cero.
                    ?>
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; padding:6px 0; border-bottom:1px dashed #f0d9a7;">
                            <div>
                                <div style="font-weight:600; color:#4e342e;"><?php echo esc_html( $con['nombre'] ); ?></div>
                                <small style="color:#8d6e63; font-size:0.78rem;"><?php echo wp_kses( $con['detalle'], array( 'span' => array( 'title' => true ) ) ); ?></small>
                            </div>
                            <div style="text-align:right; color:#c62828; font-weight:700;">$ <?php echo number_format( $con['subtotal'] ); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                    <span style="font-weight:700; color:#4e342e;">Total a pagar:</span>
                    <span style="font-weight:800; color:#b71c1c;">$ <?php echo number_format($total_pendiente); ?></span>
                </div>
            </div>
            <?php else: ?>
            <div class="lud-success-box">✅ Estás al día con tus aportes.</div>
            <?php endif; ?>

            <div class="lud-details-grid">
                <div class="lud-detail-item">
                    <strong>Mis Acciones Hoy</strong>
                    <span><?php echo $acciones; ?></span>
                    <small style="display:block; color:#999; font-size:0.75rem; margin-top:2px;">Valor acción: $ <?php echo number_format( $valor_accion_unitario ); ?></small>
                </div>
                <div class="lud-detail-item" style="position:relative;">
                    <strong>Rendimientos <?php echo $anio_actual; ?></strong>
                    <span style="color:#1565c0;">$ <?php echo number_format($rendimientos_totales, 0, ',', '.'); ?></span>
                    <?php if($acumulado_este_anio > 0): ?>
                        <small style="display:block; font-size:0.7rem; color:#888;">(Incluye $<?php echo number_format($acumulado_este_anio); ?> acumulados este año)</small>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ( ! empty( $resumen_creditos ) ): ?>
            <div class="lud-card lud-accordion-credito" style="margin-top:6px; padding:10px 12px;">
                <button type="button" class="lud-accordion-toggle" aria-expanded="false">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-weight:700; color:#1a237e;"><?php echo count( $resumen_creditos ) > 1 ? 'Créditos vigentes' : 'Crédito vigente'; ?></span>
                        <span class="lud-badge pendiente">En curso</span>
                    </div>
                    <span class="lud-accordion-caret" aria-hidden="true">▼</span>
                </button>
                <div class="lud-accordion-panel" style="display:none; margin-top:8px;">
                    <p style="color:#4a4a4a; font-size:0.88rem; margin:0 0 8px;">Mostramos monto aprobado, cuota estimada y fecha objetivo de cierre (puede ajustarse si hay refinanciación).</p>
                    <?php foreach ( $resumen_creditos as $cred ): ?>
                        <div style="background:#f7f9fc; border:1px solid #e0e6f6; padding:10px 12px; border-radius:8px; margin-bottom:8px;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                                <div>
                                    <div style="font-weight:700; color:#1a237e;">Crédito #<?php echo $cred['id']; ?> · <?php echo ucfirst( $cred['tipo'] ); ?></div>
                                    <small style="color:#5c6bc0;">Estado: <?php echo ucfirst( $cred['estado'] ); ?></small>
                                </div>
                                <div style="text-align:right;">
                                    <span style="display:block; font-size:0.8rem; color:#666;">Monto aprobado</span>
                                    <strong style="font-size:1.05rem; color:#1b5e20;">$ <?php echo number_format( $cred['monto'], 0, ',', '.' ); ?></strong>
                                </div>
                            </div>
                            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:6px; font-size:0.9rem;">
                                <div style="color:#2e7d32; font-weight:600;">Cuota: $ <?php echo number_format( $cred['cuota'], 0, ',', '.' ); ?></div>
                                <div style="color:#37474f;">Fin proyectado: <?php echo date_i18n( 'd M Y', strtotime( $cred['fecha_fin'] ) ); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <script>
            // Comentario: manejamos la apertura/cierre del acordeón de crédito vigente para mantener la UI compacta.
            document.addEventListener('DOMContentLoaded', function(){
                const acordiones = document.querySelectorAll('.lud-accordion-credito .lud-accordion-toggle');
                acordiones.forEach(function(boton){
                    boton.addEventListener('click', function(){
                        const panel = boton.nextElementSibling;
                        const abierto = boton.getAttribute('aria-expanded') === 'true';
                        boton.setAttribute('aria-expanded', abierto ? 'false' : 'true');
                        panel.style.display = abierto ? 'none' : 'block';
                        const caret = boton.querySelector('.lud-accordion-caret');
                        if (caret) caret.textContent = abierto ? '▼' : '▲';
                    });
                });
            });
        </script>
        <style>
            /* Comentario: acordeón minimalista para crédito vigente sin ocupar espacio extra. */
            .lud-accordion-credito { background:#f9fbff; border:1px solid #e5eaf5; }
            .lud-accordion-toggle { width:100%; background:transparent; border:none; padding:6px 4px; display:flex; align-items:center; justify-content:space-between; cursor:pointer; }
            .lud-accordion-toggle:focus { outline:2px solid #c5cae9; }
            .lud-accordion-caret { color:#5c6bc0; font-size:0.9rem; }
        </style>
        <?php
        return ob_get_clean();
    }

    // --- CARD 2: HISTORIAL DETALLADO ---
    /**
     * Renderiza la lista de últimos movimientos del socio.
     */
    public function render_historial_movimientos() {
        if ( ! is_user_logged_in() ) return '';

        global $wpdb;
        $user_id = get_current_user_id();
        $tabla_tx = $wpdb->prefix . 'fondo_transacciones';

        $limite = 3;
        $movimientos = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $tabla_tx WHERE user_id = %d ORDER BY fecha_registro DESC LIMIT %d",
            $user_id, $limite
        ));
        $total_movs = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tabla_tx WHERE user_id = %d", $user_id ) ) );
        $hay_mas = $total_movs > $limite;
        $nonce_hist = wp_create_nonce( 'lud_historial_nonce' );

        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header"><h3>Últimos Movimientos</h3></div>
            <div style="display:flex; gap:10px; margin-bottom:10px; align-items:flex-end;">
                <div>
                    <label class="lud-label">Desde</label>
                    <input type="date" id="hist_desde" class="lud-input" style="min-height:40px;">
                </div>
                <div>
                    <label class="lud-label">Hasta</label>
                    <input type="date" id="hist_hasta" class="lud-input" style="min-height:40px;">
                </div>
                <button class="lud-btn" id="btn_filtrar_hist" style="width:auto; padding:10px 18px; margin:0;">Filtrar</button>
            </div>
            <div id="historial_body" style="display:flex; flex-direction:column; gap:8px;">
                <?php if ( empty($movimientos) ): ?>
                    <div style="text-align:center; padding:20px; color:#777; background:#fafafa; border-radius:8px;">
                        <span style="font-size:2rem;">📭</span><br>No hay movimientos registrados aún.
                    </div>
                <?php else: ?>
                    <?php foreach($movimientos as $m): ?>
                        <?php echo $this->construir_tarjeta_movimiento( $m ); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ( $hay_mas ): ?>
                <button class="lud-btn" id="btn_cargar_mas" style="margin-top:10px; width:100%; background:#eee; color:#444; border:1px solid #ddd; box-shadow:none;">Cargar más</button>
            <?php endif; ?>
        </div>
        <div id="lud-modal-detalle" class="lud-modal" role="dialog" aria-modal="true" aria-label="Detalle de cambios">
            <div class="lud-modal-contenido">
                <button id="lud-modal-cerrar" aria-label="Cerrar detalle">×</button>
                <h4>Datos modificados</h4>
                <div id="lud-modal-detalle-texto" class="lud-modal-detalle-texto"></div>
            </div>
        </div>
        <script>
            (function(){
                const ajaxUrl = "<?php echo admin_url('admin-ajax.php'); ?>";
                const nonce = "<?php echo $nonce_hist; ?>";
                let offset = <?php echo $limite; ?>;
                const perPage = <?php echo $limite; ?>;

                function renderRows(cardsHtml, append=true){
                    const cont = document.getElementById('historial_body');
                    if (!cont) return;
                    if (!append) cont.innerHTML = '';
                    cont.insertAdjacentHTML( append ? 'beforeend' : 'afterbegin', cardsHtml );
                }

                function cargarMas(reset=false){
                    const desde = document.getElementById('hist_desde').value;
                    const hasta = document.getElementById('hist_hasta').value;
                    const data = new URLSearchParams();
                    data.append('action','lud_historial_mov');
                    data.append('nonce', nonce);
                    data.append('offset', reset ? 0 : offset);
                    data.append('limite', perPage);
                    data.append('desde', desde);
                    data.append('hasta', hasta);

                    fetch(ajaxUrl, { method:'POST', body:data, credentials:'same-origin' })
                        .then(r=>r.json())
                        .then(res=>{
                            if(res && res.rows){
                                renderRows(res.rows, !reset);
                                offset = reset ? res.next_offset : offset + perPage;
                                const btn = document.getElementById('btn_cargar_mas');
                                if(btn){ btn.style.display = res.has_more ? 'block' : 'none'; }
                            }
                        });
                }

            const btnMas = document.getElementById('btn_cargar_mas');
            if (btnMas) btnMas.addEventListener('click', function(){ cargarMas(false); });

            const btnFiltrar = document.getElementById('btn_filtrar_hist');
            if (btnFiltrar) btnFiltrar.addEventListener('click', function(){
                offset = perPage;
                cargarMas(true);
            });

            // Modal para ver cambios de datos
            const modal = document.getElementById('lud-modal-detalle');
            const modalTexto = document.getElementById('lud-modal-detalle-texto');
            const btnCerrar = document.getElementById('lud-modal-cerrar');

            if (modal && modalTexto && btnCerrar) {
                document.addEventListener('click', function(e){
                    if(e.target.classList.contains('lud-btn-detalle')){
                        e.preventDefault();
                        const texto = e.target.getAttribute('data-detalle') || '';
                        modalTexto.textContent = texto;
                        modal.style.display = 'flex';
                    }
                    if(e.target === modal || e.target === btnCerrar){
                        modal.style.display = 'none';
                        modalTexto.textContent = '';
                    }
                });
            }
        })();
        </script>
        <style>
            /* Comentario: estilos compactos para la lista de movimientos. */
            .lud-card.lud-mov-compacta {
                border:1px solid #e0e0e0; padding:10px; display:flex; justify-content:space-between; align-items:center; gap:10px;
            }
            .lud-mov-compacta .col-izq { flex:1; min-width:0; }
            .lud-mov-compacta .col-der { text-align:right; min-width:140px; }
            .lud-mov-compacta small { color:#777; }
            .lud-monto-texto { font-size:1rem; font-weight:700; color:#283593; margin-top:4px; }
            .lud-monto-num { font-size:1.2rem; font-weight:700; color:#1b5e20; margin-top:4px; }
            .lud-btn-detalle { background:#e3f2fd; color:#0d47a1; border:1px solid #bbdefb; padding:4px 8px; border-radius:6px; cursor:pointer; font-size:0.85rem; }
            .lud-btn-detalle:hover { background:#bbdefb; }
            .lud-detalle-linea { font-size:0.85rem; color:#555; }
            .lud-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.35); align-items:center; justify-content:center; z-index:9999; padding:10px; }
            .lud-modal-contenido { background:#fff; padding:18px; border-radius:10px; max-width:520px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.2); }
            .lud-modal-contenido h4 { margin-top:0; }
            .lud-modal-detalle-texto { white-space:pre-line; color:#333; line-height:1.5; font-size:0.95rem; }
            #lud-modal-cerrar { float:right; border:none; background:none; font-size:1.2rem; cursor:pointer; color:#666; }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Construye el HTML de una tarjeta de movimiento con lógica de enlaces seguros y detalles.
     */
    private function construir_tarjeta_movimiento( $movimiento ) {
        $tiene_comprobante = ! empty( $movimiento->comprobante_url );
        $link_seguro = $tiene_comprobante
            ? admin_url( 'admin-post.php?action=lud_ver_comprobante&file=' . rawurlencode( $movimiento->comprobante_url ) )
            : '';

        $detalle_bruto = isset( $movimiento->detalle ) ? (string) $movimiento->detalle : '';
        $concepto = $this->obtener_concepto_legible( $movimiento );
        $texto_comprobante = ( $movimiento->tipo === 'desembolso_credito' ) ? 'Ver contrato' : 'Ver comprobante';
        $es_actualizacion = $this->movimiento_es_actualizacion( $movimiento, $detalle_bruto );

        $texto_monto = '$ ' . number_format( $movimiento->monto );
        $clase_monto = 'lud-monto-num';
        if ( $es_actualizacion ) {
            $texto_monto = 'Actualización de datos';
            $clase_monto = 'lud-monto-texto';
            $concepto = 'Actualización de datos realizada';
        }

        $detalle_interactivo = $this->construir_detalle_movimiento( $detalle_bruto, $es_actualizacion );

        ob_start();
        ?>
        <div class="lud-card lud-mov-compacta" data-movimiento="<?php echo intval( $movimiento->id ); ?>">
            <div class="col-izq">
                <div style="font-weight:600; color:#444; display:flex; gap:8px; flex-wrap:wrap;">
                    <span><?php echo date('d/m/Y', strtotime($movimiento->fecha_registro)); ?></span>
                    <span style="color:#777;">ID: <?php echo 'TX-' . $movimiento->id; ?></span>
                </div>
                <div style="font-size:0.9rem; color:#555; margin-top:4px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                    <?php if ( $tiene_comprobante ): ?>
                        <a href="<?php echo esc_url( $link_seguro ); ?>" target="_blank" rel="noopener" style="color:#1565c0; text-decoration:underline; font-size:0.9rem;"><?php echo esc_html( $texto_comprobante ); ?></a>
                    <?php else: ?>
                        <span style="color:#999;">Sin comprobante adjunto</span>
                    <?php endif; ?>
                    <?php echo $detalle_interactivo; ?>
                </div>
            </div>
            <div class="col-der">
                <span class="lud-badge <?php echo strtolower($movimiento->estado); ?>" style="font-size:0.8rem;"><?php echo ucfirst($movimiento->estado); ?></span>
                <div class="<?php echo esc_attr( $clase_monto ); ?>"><?php echo esc_html( $texto_monto ); ?></div>
                <div style="font-size:0.85rem; color:#555;"><?php echo esc_html( $concepto ); ?></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Construye el bloque de detalle formateado o un botón modal para actualizaciones.
     */
    private function construir_detalle_movimiento( $detalle_bruto, $es_actualizacion ) {
        if ( empty( $detalle_bruto ) ) {
            return '';
        }

        if ( $es_actualizacion ) {
            $detalle_limpio = $this->formatear_detalle_actualizacion( $detalle_bruto );
            return '<button type="button" class="lud-btn-detalle" data-detalle="' . esc_attr( $detalle_limpio ) . '">Ver cambios</button>';
        }

        $detalle_legible = $this->formatear_detalle_generico( $detalle_bruto );
        return '<div class="lud-detalle-linea">' . esc_html( $detalle_legible ) . '</div>';
    }

    /**
     * Determina si un movimiento representa una actualización de datos.
     */
    private function movimiento_es_actualizacion( $movimiento, $detalle_bruto ) {
        $monto_cero = floatval( $movimiento->monto ) === 0;
        $detalle_normalizado = strtolower( $detalle_bruto );
        $contiene_palabras = ( strpos( $detalle_normalizado, 'actualización de datos' ) !== false || strpos( $detalle_normalizado, 'admin edición' ) !== false );
        return ( $movimiento->tipo === 'actualizacion_datos' ) || ( $monto_cero && $contiene_palabras );
    }

    /**
     * Formatea un detalle de actualización en texto claro separado por saltos de línea.
     */
    private function formatear_detalle_actualizacion( $detalle_bruto ) {
        // Comentario: limpiamos prefijos técnicos para mostrar solo los cambios.
        $limpio = preg_replace( '/^admin edici[oó]n[^:]*:/i', '', $detalle_bruto );
        $limpio = trim( $limpio );

        // Comentario: convertimos "Campo: 'A' -> 'B'" a "Campo: A → B".
        $limpio = preg_replace( "/'([^']*)'\\s*->\\s*'([^']*)'/", '$1 → $2', $limpio );
        $limpio = str_replace( array( " -> ", '  ' ), array( ' → ', ' ' ), $limpio );

        // Comentario: separamos por coma para mostrar cada cambio en línea distinta.
        $partes = array_map( 'trim', explode( ',', $limpio ) );
        $partes = array_filter( $partes );

        return implode( "\n• ", $partes );
    }

    /**
     * Formatea detalles generales eliminando ruido visual.
     */
    private function formatear_detalle_generico( $detalle_bruto ) {
        $detalle = trim( $detalle_bruto );
        $detalle = preg_replace( "/'([^']*)'\\s*->\\s*'([^']*)'/", '$1 → $2', $detalle );
        return $detalle;
    }

    /**
     * Devuelve un texto legible para el tipo de movimiento.
     */
    private function obtener_concepto_legible( $movimiento ) {
        $mapa = array(
            'pago_consolidado' => 'Pago reportado',
            'aporte' => 'Aporte',
            'cuota_credito' => 'Cuota de crédito',
            'multa' => 'Multa',
            'gasto_operativo' => 'Gasto Operativo',
            'ajuste_redondeo' => 'Ajuste',
            'desembolso_credito' => 'Desembolso de crédito',
            'actualizacion_datos' => 'Actualización de datos',
        );
        if ( isset( $mapa[ $movimiento->tipo ] ) ) return $mapa[ $movimiento->tipo ];
        return ucfirst( str_replace( '_', ' ', $movimiento->tipo ) );
    }

    /**
     * Responde el historial vía AJAX para paginación sin recargar.
     */
    public function ajax_historial_movimientos() {
        if ( ! is_user_logged_in() ) wp_send_json_error( 'No autorizado' );
        check_ajax_referer( 'lud_historial_nonce', 'nonce' );

        global $wpdb;
        $user_id = get_current_user_id();
        $offset = intval( $_POST['offset'] ?? 0 );
        $limite = intval( $_POST['limite'] ?? 3 );
        $desde = sanitize_text_field( $_POST['desde'] ?? '' );
        $hasta = sanitize_text_field( $_POST['hasta'] ?? '' );

        $tabla_tx = $wpdb->prefix . 'fondo_transacciones';
        $where = $wpdb->prepare( "WHERE user_id = %d", $user_id );

        if ( !empty($desde) ) {
            $where .= $wpdb->prepare( " AND DATE(fecha_registro) >= %s", $desde );
        }
        if ( !empty($hasta) ) {
            $where .= $wpdb->prepare( " AND DATE(fecha_registro) <= %s", $hasta );
        }

        $rows = $wpdb->get_results( "SELECT * FROM $tabla_tx $where ORDER BY fecha_registro DESC LIMIT $limite OFFSET $offset" );
        $total = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $tabla_tx $where" ) );

        ob_start();
        foreach ( $rows as $m ) {
            echo $this->construir_tarjeta_movimiento( $m );
        }
        $html_rows = ob_get_clean();
        $has_more = ( $offset + $limite ) < $total;
        wp_send_json( array(
            'rows' => $html_rows,
            'has_more' => $has_more,
            'next_offset' => $offset + $limite
        ) );
    }

    // --- CARD 3: PERFIL BENEFICIARIO (CORREGIDO) ---
    /**
     * Muestra y permite editar el beneficiario del socio.
     */
    public function render_perfil_beneficiario() {
        if ( ! is_user_logged_in() ) return '';

        global $wpdb;
        $user_id = get_current_user_id();
        $cuenta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );

        // --- CORRECCIÓN VITAL: Evitar error en usuarios nuevos ---
        if ( ! $cuenta ) {
            $cuenta = new stdClass();
            $cuenta->beneficiario_nombre = '';
            $cuenta->beneficiario_parentesco = '';
            $cuenta->beneficiario_telefono = '';
        }
        // ---------------------------------------------------------

        $msg = '';
        if ( isset( $_GET['lud_profile_saved'] ) ) {
            $msg = '<div class="lud-alert success">✅ Beneficiario actualizado correctamente.</div>';
        }

        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header"><h3>👤 Datos de Beneficiario</h3></div>
            <p style="font-size:0.9rem; color:#666; margin-bottom:15px; background:#e3f2fd; padding:10px; border-radius:8px;">
                <strong>Cumplimiento Art. 22:</strong> En caso de fallecimiento, estos fondos serán entregados a la persona designada.
            </p>
            <?php echo $msg; ?>

            <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST">
                <input type="hidden" name="action" value="lud_guardar_perfil">
                <?php wp_nonce_field( 'lud_perfil_nonce', 'lud_security' ); ?>

                <div class="lud-form-group">
                    <label class="lud-label">Nombre Completo del Beneficiario</label>
                    <input type="text" name="beneficiario_nombre" class="lud-input" 
                           value="<?php echo esc_attr($cuenta->beneficiario_nombre); ?>" 
                           placeholder="Nombre y Apellidos" required>
                </div>

                <div class="lud-form-group">
                    <label class="lud-label">Teléfono de Contacto</label>
                    <input type="text" name="beneficiario_telefono" class="lud-input" 
                           value="<?php echo esc_attr( isset($cuenta->beneficiario_telefono) ? $cuenta->beneficiario_telefono : '' ); ?>" 
                           placeholder="Celular o Fijo" required>
                </div>

                <div class="lud-form-group">
                    <label class="lud-label">Parentesco</label>
                    <select name="beneficiario_parentesco" class="lud-input" required>
                        <option value="">-- Seleccione --</option>
                        <?php 
                        $opciones = ['Esposo(a)', 'Hijo(a)', 'Padre/Madre', 'Hermano(a)', 'Otro'];
                        foreach($opciones as $op): 
                            $selected = ($cuenta->beneficiario_parentesco == $op) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $op; ?>" <?php echo $selected; ?>><?php echo $op; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="lud-btn" style="background-color:#546e7a;">Guardar Información</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Guarda los datos del beneficiario en la tabla financiera.
     */
    public function procesar_guardado_perfil() {
        if ( ! is_user_logged_in() ) wp_die('No autorizado');
        check_admin_referer( 'lud_perfil_nonce', 'lud_security' );

        global $wpdb;
        $user_id = get_current_user_id();
        $nombre = sanitize_text_field( $_POST['beneficiario_nombre'] );
        $telefono = sanitize_text_field( $_POST['beneficiario_telefono'] );
        $parentesco = sanitize_text_field( $_POST['beneficiario_parentesco'] );

        // Verificar si existe la cuenta
        $existe = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id) );

        if ( $existe ) {
            $wpdb->update(
                $wpdb->prefix . 'fondo_cuentas',
                array( 
                    'beneficiario_nombre' => $nombre, 
                    'beneficiario_parentesco' => $parentesco,
                    'beneficiario_telefono' => $telefono
                ),
                array( 'user_id' => $user_id )
            );
        } else {
            // INSERTAR NUEVA CUENTA SI NO EXISTE
            $wpdb->insert(
                $wpdb->prefix . 'fondo_cuentas',
                array(
                    'user_id' => $user_id,
                    'numero_acciones' => 0, // Inicia en 0 hasta que pague
                    'beneficiario_nombre' => $nombre,
                    'beneficiario_parentesco' => $parentesco,
                    'beneficiario_telefono' => $telefono,
                    'estado_socio' => 'activo'
                )
            );
        }

        // Disparar notificación y marcar fecha de última actualización.
        update_user_meta( $user_id, 'lud_ultima_actualizacion_datos', current_time( 'mysql' ) );
        do_action( 'lud_evento_datos_actualizados', $user_id, 'Zona de socios', 'Beneficiario y contacto' );

        wp_redirect( add_query_arg( 'lud_profile_saved', '1', wp_get_referer() ) );
        exit;
    }

    // --- FORMULARIO DE REGISTRO NUEVO SOCIO ---
    /**
     * Dibuja el formulario de solicitud de ingreso para nuevos socios.
     */
    public function render_formulario_registro() {
        if ( is_user_logged_in() ) return '<div class="lud-alert success">Ya tienes una sesión activa. No necesitas registrarte de nuevo.</div>';
        $msg = '';
        if ( isset($_GET['lud_reg']) && $_GET['lud_reg'] == 'ok' ) {
            return '<div class="lud-card" style="text-align:center;">
                <h3>✅ ¡Solicitud Recibida!</h3>
                <p>Tus datos han sido enviados a la Junta Directiva.</p>
                <p>Nos pondremos en contacto contigo una vez tu ingreso sea aprobado.</p>
            </div>';
        }
        if ( isset($_GET['lud_err']) ) $msg = '<div class="lud-alert error">❌ '.sanitize_text_field($_GET['lud_err']).'</div>';

        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header"><h3>📝 Solicitud de Ingreso</h3></div>
            <?php echo $msg; ?>
            <p>Diligencia este formulario para solicitar tu vinculación al Fondo de Inversión.</p>
            
            <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="lud_procesar_registro">
                <?php wp_nonce_field( 'lud_registro_nonce', 'lud_security' ); ?>

                <h4 style="border-bottom:1px solid #eee; margin-top:20px;">1. Datos Personales</h4>
                <div class="lud-form-group">
                    <label class="lud-label">Nombre Completo</label>
                    <input type="text" name="nombre_completo" class="lud-input" required>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 2fr; gap:10px;">
                    <div class="lud-form-group">
                        <label class="lud-label">Tipo Doc.</label>
                        <select name="tipo_documento" class="lud-input" required>
                            <option value="CC">C.C.</option>
                            <option value="CE">C.E.</option>
                            <option value="Pasaporte">Pasaporte</option>
                        </select>
                    </div>
                    <div class="lud-form-group">
                        <label class="lud-label">Número Documento</label>
                        <input type="text" name="numero_documento" class="lud-input" required>
                    </div>
                </div>
                <div class="lud-form-group">
                    <label class="lud-label">Fecha de Nacimiento</label>
                    <input type="date" name="fecha_nacimiento" class="lud-input" required>
                </div>
                <div class="lud-form-group">
                    <label class="lud-label">Dirección de Residencia</label>
                    <input type="text" name="direccion" class="lud-input" required>
                </div>
                <div class="lud-form-group">
                    <label class="lud-label">Ciudad y País</label>
                    <input type="text" name="ciudad_pais" class="lud-input" placeholder="Ej: Bogotá, Colombia" required>
                </div>
                <div class="lud-form-group">
                    <label class="lud-label">Teléfono de Contacto</label>
                    <input type="text" name="telefono" class="lud-input" required>
                </div>
                <div class="lud-form-group">
                    <label class="lud-label">Correo Electrónico (Será tu usuario)</label>
                    <input type="email" name="email" class="lud-input" required>
                </div>

                <h4 style="border-bottom:1px solid #eee; margin-top:20px;">2. Información del Fondo</h4>
                <div class="lud-form-group">
                    <label class="lud-label">Fecha estimada de ingreso (Si aplica)</label>
                    <input type="date" name="fecha_ingreso" class="lud-input">
                </div>
                <div class="lud-form-group">
                    <label class="lud-label">Monto Aporte Inicial ($)</label>
                    <input type="number" name="aporte_inicial" class="lud-input" placeholder="0" min="0">
                </div>

                <h4 style="border-bottom:1px solid #eee; margin-top:20px;">3. Información Financiera</h4>
                <div class="lud-form-group">
                    <label class="lud-label">Actividad Económica</label>
                    <input type="text" name="actividad_economica" class="lud-input" placeholder="Ej: Empleado, Comerciante..." required>
                </div>
                <div class="lud-form-group">
                    <label class="lud-label">Fuente de Ingresos</label>
                    <input type="text" name="origen_fondos" class="lud-input" placeholder="Ej: Salario, Ventas..." required>
                </div>
                <div class="lud-form-group">
                    <label class="lud-label">Banco / Medio de Pago Habitual</label>
                    <input type="text" name="banco" class="lud-input" placeholder="Ej: Bancolombia, Nequi..." required>
                </div>

                <h4 style="border-bottom:1px solid #eee; margin-top:20px;">4. Documentos</h4>
                <div class="lud-form-group">
                    <label class="lud-label">Copia Documento Identidad (PDF < 2MB)</label>
                    <input type="file" name="archivo_documento" class="lud-input" accept="application/pdf" required>
                </div>

                <button type="submit" class="lud-btn">Enviar Solicitud</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Procesa la inscripción de un nuevo socio y crea su usuario en WordPress.
     */
    public function procesar_registro_nuevo() {
        if ( ! isset( $_POST['lud_security'] ) || ! wp_verify_nonce( $_POST['lud_security'], 'lud_registro_nonce' ) ) wp_die('Seguridad');

        $email = sanitize_email($_POST['email']);
        $documento = sanitize_text_field($_POST['numero_documento']);
        
        if ( username_exists($documento) || email_exists($email) ) {
            wp_redirect(add_query_arg('lud_err', 'El usuario o correo ya existe', wp_get_referer())); exit;
        }

        // 1. Subir PDF
        $pdf_filename = '';
        if ( isset($_FILES['archivo_documento']) && !empty($_FILES['archivo_documento']['name']) ) {
            $file = $_FILES['archivo_documento'];

            if ( $file['error'] !== UPLOAD_ERR_OK ) {
                wp_die('Error al subir el documento de identidad.');
            }

            if ( $file['size'] > 2 * 1024 * 1024 ) {
                wp_die('El archivo excede el límite de 2MB.');
            }

            $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'pdf' => 'application/pdf' ) );

            if ( empty($filetype['ext']) || empty($filetype['type']) || $filetype['ext'] !== 'pdf' ) {
                wp_die('Solo se permiten archivos PDF válidos.');
            }
            
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/fondo_seguro/documentos/';
            if ( ! file_exists( $target_dir ) ) wp_mkdir_p( $target_dir );
            
            $pdf_filename = sanitize_file_name( 'doc_' . $documento . '_' . time() . '.' . $filetype['ext'] );
            if ( ! move_uploaded_file( $file['tmp_name'], $target_dir . $pdf_filename ) ) {
                wp_die('No se pudo guardar el documento de identidad.');
            }
        }

        // 2. Crear Usuario WordPress con contraseña aleatoria
        $password = wp_generate_password( 20, true, true );
        $user_id = wp_create_user( $documento, $password, $email );
        if ( is_wp_error($user_id) ) wp_die($user_id->get_error_message());

        wp_update_user([
            'ID' => $user_id, 
            'display_name' => sanitize_text_field($_POST['nombre_completo']),
            'role' => 'lud_socio'
        ]);
        wp_new_user_notification( $user_id, null, 'both' );

        // 3. Crear Ficha en DB
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}fondo_cuentas", [
            'user_id' => $user_id,
            'numero_acciones' => 0,
            'estado_socio' => 'pendiente', // IMPORTANTE: Entra como pendiente
            
            // Datos Personales
            'tipo_documento' => sanitize_text_field($_POST['tipo_documento']),
            'numero_documento' => $documento,
            'fecha_nacimiento' => sanitize_text_field($_POST['fecha_nacimiento']),
            'direccion_residencia' => sanitize_text_field($_POST['direccion']),
            'ciudad_pais' => sanitize_text_field($_POST['ciudad_pais']),
            'telefono_contacto' => sanitize_text_field($_POST['telefono']),
            'email_contacto' => $email,
            
            // Datos Fondo
            'fecha_ingreso_fondo' => sanitize_text_field($_POST['fecha_ingreso']),
            'aporte_inicial' => floatval($_POST['aporte_inicial']),
            
            // Datos Financieros
            'actividad_economica' => sanitize_text_field($_POST['actividad_economica']),
            'origen_fondos' => sanitize_text_field($_POST['origen_fondos']),
            'banco_medio_pago' => sanitize_text_field($_POST['banco']),
            
            // Docs
            'url_documento_id' => $pdf_filename
        ]);

        wp_redirect( add_query_arg( 'lud_reg', 'ok', wp_get_referer() ) );
        exit;
    }
}
