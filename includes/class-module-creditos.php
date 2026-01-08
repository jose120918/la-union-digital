<?php
/**
 * Módulo de créditos: simulador, solicitudes y flujos de aprobación.
 *
 * Administra la lógica de préstamos corrientes y ágiles, incluidas validaciones
 * de liquidez, sanciones y firmas digitales tanto del solicitante como del deudor solidario.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Module_Creditos {

    /**
     * Registra shortcodes y acciones para solicitudes y aprobaciones.
     */
    public function __construct() {
        add_shortcode( 'lud_simulador_credito', array( $this, 'render_simulador' ) );
        add_action( 'admin_post_lud_solicitar_credito', array( $this, 'procesar_solicitud' ) );
        
        // Hooks para el Deudor Solidario
        add_shortcode( 'lud_zona_deudor', array( $this, 'render_zona_deudor' ) );
        add_action( 'admin_post_lud_firmar_deudor', array( $this, 'procesar_firma_deudor' ) );

        // Ajustes de esquema y cola de liquidez
        $this->asegurar_estado_fila_liquidez();
        $this->asegurar_columnas_documentos();
        add_action( 'init', array( $this, 'liberar_fila_por_liquidez' ) );
    }

    // --- 1. HELPERS DE VALIDACIÓN ---

    /**
     * Calcula la liquidez disponible restando gastos, préstamos activos y reserva de secretaría.
     *
     * @return float Monto disponible para nuevos créditos.
     */
    public static function get_liquidez_disponible() {
        global $wpdb;
        $fecha_corte_operativo = defined( 'LUD_FECHA_CORTE_OPERATIVO' ) ? LUD_FECHA_CORTE_OPERATIVO : date( 'Y-01-01' );
        $anio_corte_operativo = intval( date( 'Y', strtotime( $fecha_corte_operativo ) ) );

        // Dinero Físico desde el corte operativo (saldo base + movimientos nuevos).
        $entradas = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(rd.monto)
             FROM {$wpdb->prefix}fondo_recaudos_detalle rd
             LEFT JOIN {$wpdb->prefix}fondo_transacciones tx ON rd.transaccion_id = tx.id
             WHERE rd.fecha_recaudo >= %s
               AND (tx.metodo_pago IS NULL OR tx.metodo_pago <> 'importacion')",
            $fecha_corte_operativo
        ));
        $gastos = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE fecha_gasto >= %s",
            $fecha_corte_operativo
        ));
        $prestado = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(saldo_actual)
             FROM {$wpdb->prefix}fondo_creditos
             WHERE estado IN ('activo', 'mora')
               AND fecha_solicitud >= %s
               AND (datos_entrega IS NULL OR datos_entrega NOT LIKE 'Importación%%')",
            $fecha_corte_operativo
        ));
        $intereses_pagados = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(utilidad_asignada) FROM {$wpdb->prefix}fondo_utilidades_mensuales WHERE estado = 'liquidado' AND anio >= %d",
            $anio_corte_operativo
        ));

        // Reservas que NO se pueden prestar (Secretaría)
        // Nota: Multas e Intereses SÍ se prestan durante el año.
        $recaudo_sec = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(rd.monto)
             FROM {$wpdb->prefix}fondo_recaudos_detalle rd
             LEFT JOIN {$wpdb->prefix}fondo_transacciones tx ON rd.transaccion_id = tx.id
             WHERE rd.concepto = 'cuota_secretaria'
               AND rd.fecha_recaudo >= %s
               AND (tx.metodo_pago IS NULL OR tx.metodo_pago <> 'importacion')",
            $fecha_corte_operativo
        ));
        $gasto_sec = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE categoria = 'secretaria' AND fecha_gasto >= %s",
            $fecha_corte_operativo
        ));
        $reserva_sec = floatval( defined( 'LUD_BASE_SECRETARIA' ) ? LUD_BASE_SECRETARIA : 0 )
            + floatval($recaudo_sec) - floatval($gasto_sec);

        $saldo_base_caja = floatval( defined( 'LUD_BASE_DISPONIBLE' ) ? LUD_BASE_DISPONIBLE : 0 )
            + floatval( defined( 'LUD_BASE_SECRETARIA' ) ? LUD_BASE_SECRETARIA : 0 );

        $dinero_fisico = $saldo_base_caja + floatval($entradas) - floatval($gastos) - floatval($prestado) - floatval($intereses_pagados);
        $liquidez = $dinero_fisico - $reserva_sec;
        return max($liquidez, 0); 
    }

    /**
     * Verifica si el usuario tiene sanción por mora en los últimos 3 meses
     * Cumple Estatuto Art 8.2: "El incumplimiento... tendrá una sanción de tres meses"
     *
     * @param int $user_id ID del socio.
     * @return bool True si está sancionado.
     */
    public static function verificar_sancion_mora($user_id) {
        global $wpdb;
        // 1. Buscar si pagó alguna MULTA en los últimos 90 días
        $fecha_limite = date('Y-m-d', strtotime('-90 days'));
        $multas_recentes = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}fondo_recaudos_detalle rd
             LEFT JOIN {$wpdb->prefix}fondo_transacciones tx ON rd.transaccion_id = tx.id
             WHERE rd.user_id = %d
               AND rd.concepto = 'multa'
               AND rd.fecha_recaudo >= %s
               AND (tx.metodo_pago IS NULL OR tx.metodo_pago <> 'importacion')",
            $user_id, $fecha_limite
        ));

        // 2. Buscar si tiene algún crédito actualmente en estado 'mora'
        $credito_en_mora = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_creditos WHERE user_id = %d AND estado = 'mora'", 
            $user_id
        ));

        if ( $multas_recentes > 0 || $credito_en_mora > 0 ) {
            return true; // ESTÁ SANCIONADO
        }
        return false;
    }

    /**
     * Calcula un puntaje crediticio (0-100) según historial de cuotas y moras.
     * Suma puntos por cuotas pagadas en estado "pagado" y resta por estados "mora/parcial".
     */
    public static function calcular_score_socio($user_id) {
        global $wpdb;
        // Comentario: recopilamos los créditos del socio para evaluar su historial.
        $creditos = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, estado FROM {$wpdb->prefix}fondo_creditos WHERE user_id = %d",
            $user_id
        ) );

        if ( empty( $creditos ) ) {
            return 50; // Comentario: sin historial, partimos de un score neutro.
        }

        $creditos_ids = array_map( 'intval', wp_list_pluck( $creditos, 'id' ) );
        $lista_ids = implode( ',', $creditos_ids );

        $cuotas_pagadas = 0;
        $cuotas_mora = 0;
        $total_cuotas = 0;

        if ( ! empty( $lista_ids ) ) {
            $estados_cuotas = $wpdb->get_col(
                "SELECT estado FROM {$wpdb->prefix}fondo_amortizacion WHERE credito_id IN ($lista_ids)"
            );

            foreach ( $estados_cuotas as $estado ) {
                $total_cuotas++;
                if ( $estado === 'pagado' ) {
                    $cuotas_pagadas++;
                } elseif ( $estado === 'mora' || $estado === 'parcial' ) {
                    $cuotas_mora++;
                }
            }
        }

        // Comentario: si aún no hay cuotas creadas, usamos estados de crédito como señal.
        if ( $total_cuotas === 0 ) {
            $creditos_pagados = array_reduce( $creditos, function( $carry, $cred ) {
                return $carry + ( $cred->estado === 'pagado' ? 1 : 0 );
            }, 0 );
            $creditos_en_mora = array_reduce( $creditos, function( $carry, $cred ) {
                return $carry + ( $cred->estado === 'mora' ? 1 : 0 );
            }, 0 );

            $score_base = 60 + ( $creditos_pagados * 8 ) - ( $creditos_en_mora * 12 );
            return max( 0, min( 100, round( $score_base ) ) );
        }

        // Comentario: ponderamos cumplimiento y moras para normalizar entre 0 y 100.
        $puntualidad = ( $cuotas_pagadas / $total_cuotas ) * 60; // hasta 60 puntos por buen pago
        $penalizacion = ( $cuotas_mora / $total_cuotas ) * 80;   // resta fuerte por moras

        $score = 40 + $puntualidad - $penalizacion;

        return max( 0, min( 100, round( $score ) ) );
    }

    // --- 2. SIMULADOR (Frontend Solicitante) ---
    /**
     * Renderiza el simulador de crédito para el socio solicitante.
     */
    public function render_simulador() {
        if ( ! is_user_logged_in() ) return '<p class="lud-alert error">Inicia sesión para simular.</p>';

        global $wpdb;
        $user_id = get_current_user_id();
        $score_crediticio = self::calcular_score_socio($user_id);
        $etiqueta_score = 'Viabilidad media';
        $color_score = '#f9a825';
        if ( $score_crediticio >= 80 ) {
            $etiqueta_score = 'Alta viabilidad';
            $color_score = '#2e7d32';
        } elseif ( $score_crediticio < 50 ) {
            $etiqueta_score = 'Baja viabilidad';
            $color_score = '#c62828';
        }

        // A. Validar Sanciones (Lista Negra)
        if ( self::verificar_sancion_mora($user_id) ) {
            return '<div class="lud-card" style="border-left:5px solid #c62828;">
                        <h3 style="color:#c62828">🚫 Creditos Bloqueados</h3>
                        <p>El sistema ha detectado <b>retrasos en tus pagos</b> en los últimos 3 meses.</p>
                        <p>Según los estatutos, debes esperar 90 días sin moras para volver a solicitar un crédito.</p>
                    </div>';
        }

        // B. Validar Liquidez del Fondo
        $liquidez = self::get_liquidez_disponible();
        // Bandera para notificar que se aceptan solicitudes pero quedarán en cola por liquidez baja
        $en_modo_fila = ( $liquidez < 100000 );

        $creditos_en_fila = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_creditos WHERE estado = 'fila_liquidez'" ) );
        
        // C. Obtener Datos del Socio
        $cuenta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );
        if ( ! $cuenta ) return '<div class="lud-card">No tienes cuenta activa.</div>';

        $ahorro_actual = floatval( $cuenta->saldo_ahorro_capital );
        $capacidad_maxima = $ahorro_actual * 3;
        
        // D. REGLA DEL 70% (Refinanciación)
        $credito_activo = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE user_id = $user_id AND estado = 'activo' LIMIT 1" );
        
        $modo_refinanciacion = false;
        $saldo_pendiente = 0;
        $msg_refinanciacion = '';
        $bloqueo_70 = false;
        $solo_credito_agil = false; // Comentario: si no cumple 70%, bloquea solo corrientes.
        $bloqueo_corriente_diciembre = ( date('m') === '12' ); // Comentario: Art 8.1, sin corrientes en diciembre.

        if ( $credito_activo ) {
            $monto_prestado = floatval($credito_activo->monto_aprobado); // Usar el aprobado, no el solicitado
            $saldo_pendiente = floatval($credito_activo->saldo_actual);
            $pagado = $monto_prestado - $saldo_pendiente;
            
            // Evitar división por cero
            $porcentaje_pagado = ($monto_prestado > 0) ? ($pagado / $monto_prestado) * 100 : 0;

            if ( $porcentaje_pagado < 70 ) {
                $bloqueo_70 = true;
                $solo_credito_agil = true; // Comentario: puede pedir Ágil, pero no Corriente/refinanciación.
                $msg_refinanciacion = '<div class="lud-alert error">
                    <strong>⚠️ Crédito vigente</strong><br>
                    Has pagado el <b>'.number_format($porcentaje_pagado, 1).'%</b> de tu crédito corriente actual.<br>
                    Para otro crédito corriente debes haber pagado mínimo el <b>70%</b>. Puedes solicitar un crédito Ágil independientemente.
                </div>';
            } else {
                $modo_refinanciacion = true;
                $msg_refinanciacion = '<div class="lud-alert success">
                    <strong>🔄 Modo Refinanciación Activado</strong><br>
                    Has pagado el <b>'.number_format($porcentaje_pagado, 1).'%</b>. Cumples el requisito para refinanciar.<br>
                    <small>Se descontará tu saldo pendiente ($'.number_format($saldo_pendiente).') del nuevo desembolso.</small>
                </div>';

                // Comentario: la refinanciación requiere crédito corriente, por tanto se bloquea en diciembre.
                if ( $bloqueo_corriente_diciembre ) {
                    $solo_credito_agil = false;
                }
            }
        }

        // Topes finales
        $tope_corriente = min($capacidad_maxima, $liquidez);
        $tope_agil = min(1500000, $liquidez);

        // Socios para Codeudor
        $args_socios = array(
            'role'    => 'lud_socio',
            'exclude' => array( $user_id ), // No mostrarse a sí mismo
            'fields'  => array( 'ID', 'display_name' )
        );
        
        // Si quieres mantener la validación de estado activo en la tabla personalizada:
        // Hacemos un cruce manual o una subconsulta. Por rendimiento y dado que son pocos usuarios:
        $query_socios = new WP_User_Query( $args_socios );
        $candidatos = $query_socios->get_results();
        
        $socios = [];
        foreach($candidatos as $cand) {
            // Verificar si está activo en la tabla financiera
            $estado = $wpdb->get_var("SELECT estado_socio FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = {$cand->ID}");
            if ($estado == 'activo') {
                $socios[] = $cand;
            }
        }

        $msg = '';
        if ( isset( $_GET['lud_msg'] ) && $_GET['lud_msg'] == 'success' ) {
            $msg = '<div class="lud-alert success">✅ <strong>¡Solicitud Enviada!</strong><br>Revisa tu correo.</div>';
        }

        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header">
                <h3>Simulador de Crédito</h3>
            </div>
            <div style="margin:10px 0 15px; padding:12px; border:1px solid #e0e0e0; border-radius:10px; background:#fafafa;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                    <div>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <div style="font-size:0.9rem; color:#666;">Score de pago</div>
                            <span title="El score suma cuotas pagadas a tiempo, resta puntos por cuotas en mora/parcial y evalúa cuántos créditos terminaste correctamente. Se normaliza entre 0 y 100 para priorizar la cola de liquidez." style="font-size:0.9rem; color:#0d47a1; cursor:help;">ℹ️</span>
                        </div>
                        <div style="font-size:1.6rem; font-weight:700; color:<?php echo $color_score; ?>;"><?php echo $score_crediticio; ?>/100</div>
                        <small style="color:#555;"><?php echo esc_html( $etiqueta_score ); ?></small>
                    </div>
                    <div style="flex:1;">
                        <div style="height:12px; background:#e0e0e0; border-radius:20px; overflow:hidden;">
                            <div style="height:100%; width:<?php echo $score_crediticio; ?>%; background:linear-gradient(90deg, #c62828, #f9a825, #2e7d32);"></div>
                        </div>
                        <small style="display:block; margin-top:6px; color:#777;">Si mantienes pagos puntuales y sin mora activa, tu score sube y mejoras prioridad en la cola de liquidez.</small>
                    </div>
                </div>
            </div>
            <?php if ( $en_modo_fila ): ?>
                <div class="lud-alert success lud-alert-compacta" style="align-items:flex-start;">
                    <span style="font-size:1.3rem;" aria-hidden="true">⏳</span>
                    <div>
                        <div style="font-weight:700;">Solicitudes en fila por liquidez</div>
                        <div style="font-size:0.9rem; color:#1b5e20;">Tu solicitud se registrará y quedará en cola. Se enviará a Tesorería en el mismo orden cuando exista liquidez.</div>
                        <div class="lud-badge pendiente" style="margin-top:8px; font-size:0.8rem;">Disponible: $ <?php echo number_format($liquidez, 0, ',', '.'); ?></div>
                        <?php if ( $creditos_en_fila > 0 ): ?>
                            <div style="font-size:0.8rem; color:#33691e; margin-top:6px;">Solicitudes en espera: <?php echo $creditos_en_fila; ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php echo $msg; ?>
            <?php echo $msg_refinanciacion; ?>
            <?php if ( $bloqueo_corriente_diciembre ): ?>
                <div class="lud-alert error lud-alert-compacta">❄️ Por estatutos (Art 8.1), no se radican créditos corrientes en Diciembre.</div>
            <?php endif; ?>

            <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST" id="formCredito">
                <input type="hidden" name="action" value="lud_solicitar_credito">
                <?php wp_nonce_field( 'lud_credito_nonce', 'lud_security' ); ?>
                
                <input type="hidden" id="saldo_pendiente" value="<?php echo $saldo_pendiente; ?>">
                <input type="hidden" id="modo_refinanciacion" value="<?php echo $modo_refinanciacion ? '1' : '0'; ?>">

                <div class="lud-form-group">
                    <label class="lud-label">Tipo de Crédito</label>
                    <div class="lud-radio-group">
                        <label class="lud-radio-card <?php echo $solo_credito_agil ? '' : 'selected'; ?>" id="opt_corriente">
                            <input type="radio" name="tipo_credito" value="corriente" <?php echo ($solo_credito_agil || $bloqueo_corriente_diciembre) ? 'disabled' : 'checked'; ?> onchange="cambiarTipo('corriente')">
                            <div>
                                <span class="lud-radio-title">🏢 Corriente (2% mes)</span>
                                <span class="lud-radio-desc">Cupo Máx: $<?php echo number_format($tope_corriente,0); ?>. Plazo máx 36 meses.</span>
                            </div>
                        </label>
                        
                        <?php if(!$modo_refinanciacion): // No se puede pedir Ágil si se está refinanciando un Corriente ?>
                        <label class="lud-radio-card <?php echo $solo_credito_agil ? 'selected' : ''; ?>" id="opt_agil">
                            <input type="radio" name="tipo_credito" value="agil" onchange="cambiarTipo('agil')" <?php echo $solo_credito_agil ? 'checked' : ''; ?>>
                            <div>
                                <span class="lud-radio-title">⚡ Ágil (1.5% mes)</span>
                                <span class="lud-radio-desc">Cupo Máx: $<?php echo number_format($tope_agil,0); ?>. Plazo fijo 1 mes.</span>
                            </div>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="lud-form-group">
                    <label class="lud-label">Monto a Solicitar (Total Nuevo Crédito)</label>
                    <input type="number" id="monto" name="monto" class="lud-input" min="50000" step="10000" required placeholder="0">
                    <small style="color:#2e7d32; display:block; margin-top:5px;">💰 Disponible en el Fondo: $<?php echo number_format($liquidez,0); ?></small>
                </div>

                <div class="lud-form-group" id="bloque_plazo">
                    <label class="lud-label">Plazo (Meses)</label>
                    <input type="range" id="plazo_range" min="1" max="36" value="12" class="lud-range" oninput="actualizarPlazo(this.value)">
                    <div style="display:flex; justify-content:space-between; margin-top:5px;">
                        <span id="plazo_display" style="font-weight:bold; color:#1565c0;">12 meses</span>
                        <small>Máx 36</small>
                    </div>
                    <input type="hidden" name="plazo" id="input_plazo" value="12">
                </div>

                <div class="lud-sim-result">
                    <div class="lud-sim-row"><span>Capital mensual:</span><span id="sim_capital">$0</span></div>
                    <div class="lud-sim-row"><span>Interés mensual inicial (<span id="sim_tasa">2%</span>):</span><span id="sim_interes">$0</span></div>
                    <div class="lud-sim-row"><span>Interés total del crédito:</span><span id="sim_interes_total">$0</span></div>
                    <div class="lud-sim-row"><span>Cuota inicial:</span><span id="sim_cuota_inicial">$0</span></div>
                    <div class="lud-sim-total"><span>Cuota final aprox:</span><span id="sim_total">$0</span></div>
                    <small style="display:block; color:#777; margin-top:6px;">El interés total es la suma de los intereses mensuales a lo largo del plazo.</small>
                    
                    <div id="bloque_cruce" style="display:none; margin-top:15px; border-top:1px solid #c8e6c9; padding-top:10px;">
                        <div class="lud-sim-row" style="color:#c62828;"><span>(-) Saldo Crédito Anterior:</span><span>- $<?php echo number_format($saldo_pendiente); ?></span></div>
                        <div class="lud-sim-row" style="font-weight:bold; color:#2e7d32; font-size:1.1rem;"><span>(=) DINERO A RECIBIR:</span><span id="sim_neto">$0</span></div>
                    </div>
                    
                    <div id="sim_warning" style="display:none; color:#c62828; font-size:0.8rem; margin-top:10px; background:#ffebee; padding:5px;">⚠️ Error: El monto debe cubrir al menos la deuda anterior.</div>
                    <div id="sim_cuota_min" class="lud-alert error lud-alert-compacta" style="display:none; margin-top:10px;">⚠️ La cuota mensual no puede ser menor a $50.000 según estatutos.</div>
                </div>

                <div class="lud-form-group" id="bloque_codeudor">
                    <label class="lud-label">Deudor Solidario (Socio)</label>
                    <select name="deudor_id" class="lud-input" required>
                        <option value="">-- Seleccionar Socio --</option>
                        <?php foreach($socios as $s): ?>
                            <option value="<?php echo $s->ID; ?>"><?php echo $s->display_name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lud-form-group">
                    <label class="lud-label">Tu Firma Digital</label>
                    <div style="border: 2px dashed #ccc; background:#fff; cursor:crosshair; border-radius:8px;">
                        <canvas id="signature-pad" width="400" height="150" style="width:100%; touch-action: none;"></canvas>
                    </div>
                    <button type="button" onclick="limpiarFirma()" style="color:#d32f2f; background:none; border:none; cursor:pointer; margin-top:5px;">🗑️ Borrar Firma</button>
                    <input type="hidden" name="signature_data" id="signature_data">
                </div>

                <button type="submit" class="lud-btn" id="btn_solicitar">Radicar Solicitud</button>
            </form>
        </div>
        <script>
            const maxCorriente = <?php echo $tope_corriente; ?>;
            const maxAgil = <?php echo $tope_agil; ?>;
            const saldoPendiente = parseFloat(document.getElementById('saldo_pendiente').value) || 0;
            const esRefinanciacion = (document.getElementById('modo_refinanciacion').value === '1');
            const soloAgil = <?php echo $solo_credito_agil ? 'true' : 'false'; ?>;
            const bloqueoCorrienteDiciembre = <?php echo $bloqueo_corriente_diciembre ? 'true' : 'false'; ?>;

            // --- CANVAS FIRMA ---
            var canvas = document.getElementById('signature-pad');
            var ctx = canvas.getContext('2d');
            var isDrawing = false;
            function resizeCanvas() {
                var ratio = Math.max(window.devicePixelRatio || 1, 1);
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                ctx.scale(ratio, ratio);
            }
            window.addEventListener("resize", resizeCanvas);
            resizeCanvas();
            function getPos(e) {
                var rect = canvas.getBoundingClientRect();
                var clientX = e.touches ? e.touches[0].clientX : e.clientX;
                var clientY = e.touches ? e.touches[0].clientY : e.clientY;
                return { x: clientX - rect.left, y: clientY - rect.top };
            }
            function startDraw(e) { isDrawing = true; var pos = getPos(e); ctx.beginPath(); ctx.moveTo(pos.x, pos.y); e.preventDefault(); }
            function moveDraw(e) { if(!isDrawing) return; var pos = getPos(e); ctx.lineTo(pos.x, pos.y); ctx.stroke(); e.preventDefault(); }
            function endDraw() { isDrawing = false; document.getElementById('signature_data').value = canvas.toDataURL(); }
            canvas.addEventListener('mousedown', startDraw); canvas.addEventListener('mousemove', moveDraw); canvas.addEventListener('mouseup', endDraw);
            canvas.addEventListener('touchstart', startDraw); canvas.addEventListener('touchmove', moveDraw); canvas.addEventListener('touchend', endDraw);
            function limpiarFirma() { ctx.clearRect(0, 0, canvas.width, canvas.height); document.getElementById('signature_data').value = ''; }

            // --- LÓGICA SIMULADOR ---
            let tipoActual = 'corriente';
            function cambiarTipo(tipo) {
                tipoActual = tipo;
                document.querySelectorAll('.lud-radio-card').forEach(el => el.classList.remove('selected'));
                document.getElementById('opt_' + tipo).classList.add('selected');
                
                if (tipo === 'corriente') {
                    document.getElementById('bloque_plazo').style.display = 'block';
                    document.getElementById('monto').max = maxCorriente;
                    document.getElementById('sim_tasa').innerText = '2%';
                } else {
                    document.getElementById('bloque_plazo').style.display = 'none';
                    document.getElementById('monto').max = maxAgil;
                    document.getElementById('sim_tasa').innerText = '1.5%';
                    document.getElementById('input_plazo').value = 1;
                }
                calcular();
            }

            function actualizarPlazo(val) { 
                document.getElementById('plazo_display').innerText = val + ' meses'; 
                document.getElementById('input_plazo').value = val; 
                calcular(); 
            }

            function calcular() {
                const monto = parseFloat(document.getElementById('monto').value) || 0;
                let plazo = parseInt(document.getElementById('input_plazo').value) || 1;
                let tasa = (tipoActual === 'corriente') ? 0.02 : 0.015;
                if (tipoActual === 'agil') plazo = 1;

                const capitalMensual = monto / plazo;
                const interesMensual = monto * tasa;
                const cuotaInicial = capitalMensual + interesMensual;
                const interesTotal = monto * tasa * ((plazo + 1) / 2);
                const interesFinal = capitalMensual * tasa;
                const cuotaFinal = capitalMensual + interesFinal;

                document.getElementById('sim_capital').innerText = '$ ' + new Intl.NumberFormat().format(Math.round(capitalMensual));
                document.getElementById('sim_interes').innerText = '$ ' + new Intl.NumberFormat().format(Math.round(interesMensual));
                document.getElementById('sim_interes_total').innerText = '$ ' + new Intl.NumberFormat().format(Math.round(interesTotal));
                document.getElementById('sim_cuota_inicial').innerText = '$ ' + new Intl.NumberFormat().format(Math.round(cuotaInicial));
                document.getElementById('sim_total').innerText = '$ ' + new Intl.NumberFormat().format(Math.round(cuotaFinal));

                // Validación Refinanciación
                let valido = true;
                let maximo = (tipoActual === 'corriente') ? maxCorriente : maxAgil;
                const firma = document.getElementById('signature_data').value;
                const alertaCuota = document.getElementById('sim_cuota_min');

                if (esRefinanciacion) {
                    document.getElementById('bloque_cruce').style.display = 'block';
                    const neto = monto - saldoPendiente;
                    document.getElementById('sim_neto').innerText = '$ ' + new Intl.NumberFormat().format(Math.round(neto));
                    
                    if (neto < 0) { // No puede pedir menos de lo que debe
                        valido = false;
                        document.getElementById('sim_warning').innerText = '⚠️ El monto debe ser mayor a tu deuda actual ($'+saldoPendiente+')';
                        document.getElementById('sim_warning').style.display = 'block';
                    } else {
                        document.getElementById('sim_warning').style.display = 'none';
                    }
                } else {
                    document.getElementById('bloque_cruce').style.display = 'none';
                }

                // Regla de cuota mínima: ninguna cuota mensual puede ser menor a $50.000 según estatutos.
                if (tipoActual === 'corriente' && cuotaFinal < 50000) {
                    valido = false;
                    alertaCuota.style.display = 'block';
                } else {
                    alertaCuota.style.display = 'none';
                }

                if (monto > maximo || monto <= 0 || !firma) valido = false;
                if (bloqueoCorrienteDiciembre && tipoActual === 'corriente') valido = false;
                
                const btn = document.getElementById('btn_solicitar');
                btn.disabled = !valido;
                btn.style.opacity = (!valido) ? '0.5' : '1';
            }

            document.getElementById('monto').addEventListener('input', calcular);
            canvas.addEventListener('mouseup', calcular); canvas.addEventListener('touchend', calcular);
            
            // Iniciar
            if (soloAgil || bloqueoCorrienteDiciembre) {
                cambiarTipo('agil');
            } else {
                cambiarTipo('corriente');
            }
        </script>
        <style>
            .lud-range { width: 100%; margin: 10px 0; accent-color: #1565c0; } 
            .lud-sim-result { background: #f0f4c3; padding: 15px; border-radius: 8px; border: 1px solid #dce775; margin-bottom: 20px; } 
            .lud-sim-row { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 5px; color: #555; } 
            .lud-sim-total { display: flex; justify-content: space-between; font-weight: bold; font-size: 1.1rem; color: #33691e; border-top: 1px dashed #afb42b; padding-top: 8px; margin-top: 5px; } 
            .lud-radio-card.selected { border-color: #1565c0; background: #e3f2fd; }
        </style>
        <?php
        return ob_get_clean();
    }

    // --- 3. PROCESAR SOLICITUD (BACKEND) ---
    /**
     * Valida la solicitud de crédito y la registra en base de datos.
     */
    public function procesar_solicitud() {
        if ( ! isset( $_POST['lud_security'] ) || ! wp_verify_nonce( $_POST['lud_security'], 'lud_credito_nonce' ) ) wp_die('Seguridad');
        
        global $wpdb;
        $user_id = get_current_user_id();

        // 1. RE-VALIDACIÓN DE SANCIONES (Server-Side)
        if ( self::verificar_sancion_mora($user_id) ) {
            wp_die('<div style="color:red; text-align:center;"><h1>⛔ Solicitud Rechazada</h1><p>Existe una sanción activa por mora en los últimos 90 días.</p></div>');
        }

        $tipo = sanitize_text_field( $_POST['tipo_credito'] );
        $monto = floatval( $_POST['monto'] );
        $plazo = intval( $_POST['plazo'] );
        $deudor_id = intval( $_POST['deudor_id'] );
        $firma_base64 = isset($_POST['signature_data']) ? $_POST['signature_data'] : '';

        // 2. REGLA DICIEMBRE
        $mes_actual = date('m');
        $aviso_diciembre = "";
        $liquidez = self::get_liquidez_disponible();

        if ( $mes_actual == '12' && $tipo === 'corriente' ) {
            wp_die('<div style="padding:30px; font-family:sans-serif; color:#b71c1c;"><h2>❄️ Solicitud bloqueada</h2><p>Por estatutos (Art 8.1), no se radican créditos corrientes en Diciembre.</p></div>');
        } elseif ( $mes_actual == '12' ) {
            // Comentario: los créditos ágiles siguen mostrando aviso de entrega en enero.
            $aviso_diciembre = " [Solicitud Diciembre: Desembolso Enero]";
        }

        $en_fila_liquidez = false;
        if ( $mes_actual != '12' && $monto > $liquidez ) {
            // Si no hay liquidez suficiente, la solicitud entra en fila pero no se rechaza
            $en_fila_liquidez = true;
            $aviso_diciembre .= " [FILA_LIQUIDEZ: Disponible $".number_format($liquidez, 0, ',', '.')."]";
        }

        // 3. REGLA DEL 70% (Server-Side)
        $credito_activo = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE user_id = $user_id AND estado = 'activo' LIMIT 1" );
        if ( $credito_activo && $tipo === 'corriente' ) {
            $pagado = floatval($credito_activo->monto_aprobado) - floatval($credito_activo->saldo_actual);
            $pct = ($pagado / floatval($credito_activo->monto_aprobado)) * 100;

            if ( $pct < 70 ) wp_die("Error: No cumples con el 70% pagado para un nuevo crédito corriente.");
            if ( $this->es_credito_refinanciado( $credito_activo ) ) {
                wp_die('<div style="padding:30px; font-family:sans-serif; color:#b71c1c;"><h2>⚠️ Refinanciación no permitida</h2><p>Este crédito ya fue refinanciado una vez. Debes terminar de pagarlo.</p></div>');
            }
            
            // Si pasa, agregamos nota de refinanciación
            $aviso_diciembre .= " || REFINANCIACIÓN: Se cruza con Crédito #{$credito_activo->id}. Saldo anterior: $".number_format($credito_activo->saldo_actual);
            $aviso_diciembre .= " || REFINANCIA_ORIGEN:{$credito_activo->id}";
        }

        // Cálculos
        $tasa = ($tipo == 'corriente') ? 2.00 : 1.50;
        if ($tipo == 'agil') $plazo = 1;
        
        // Cuota estimada con amortización alemana centralizada (capital constante + interés sobre saldo)
        $fecha_calculo = current_time( 'mysql' );
        $resultado_amortizacion = LUD_Amortizacion::construir_tabla_amortizacion(
            $monto,
            $tasa,
            $plazo,
            $fecha_calculo,
            $tipo
        );
        $resumen_amortizacion = $resultado_amortizacion['resumen'] ?? array();
        $cuotas_amortizacion = $resultado_amortizacion['cuotas'] ?? array();
        $cuota_inicial = $resumen_amortizacion['cuota_inicial'] ?? 0;
        $cuota_final = 0;
        if ( ! empty( $cuotas_amortizacion ) ) {
            $ultima_cuota = end( $cuotas_amortizacion );
            $cuota_final = $ultima_cuota['total'] ?? 0;
        }

        // Regla estatutaria: la cuota mensual no puede ser inferior a $50.000
        if ( $tipo === 'corriente' && $cuota_final < 50000 ) {
            wp_die( '<div style="padding:30px; font-family:sans-serif; color:#b71c1c;"><h2>⚠️ Solicitud no válida</h2><p>La cuota mensual resultante es inferior a $50.000. Ajusta monto o plazo según los estatutos.</p></div>' );
        }
        
        $codigo_unico = strtoupper( uniqid('CRED-') );

        // Guardar Firma
        $upload_dir = wp_upload_dir();
        $firmas_dir = $upload_dir['basedir'] . '/fondo_seguro/firmas/';
        if ( ! file_exists( $firmas_dir ) ) mkdir( $firmas_dir, 0755, true );
        $firma_filename = 'solic_' . $user_id . '_' . time() . '.png';
        
        if ( empty($firma_base64) || strpos($firma_base64, 'data:image/png;base64,') !== 0 ) {
            wp_die('Error: Firma digital requerida.');
        }

        $data = explode(',', $firma_base64);
        $decoded_firma = base64_decode($data[1], true);

        if ( $decoded_firma === false ) {
            wp_die('Error: Firma digital inválida.');
        }

        file_put_contents($firmas_dir . $firma_filename, $decoded_firma);

        // --- NUEVO: CAPTURA DE METADATOS FORENSES ---
        $ip_address = $_SERVER['REMOTE_ADDR'];
        // Soporte para proxies (Cloudflare, etc)
        if ( !empty($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
            $ip_address = sanitize_text_field(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 250) : 'Unknown';
        // ---------------------------------------------

        // Insertar en BD (ACTUALIZADO)
        $wpdb->insert(
            $wpdb->prefix . 'fondo_creditos',
            array(
                'user_id' => $user_id, 'tipo_credito' => $tipo, 'monto_solicitado' => $monto,
                'codigo_seguimiento' => $codigo_unico, 'plazo_meses' => $plazo, 'tasa_interes' => $tasa, 
                'cuota_estimada' => $cuota_inicial, 'deudor_solidario_id' => $deudor_id, 
                'firma_solicitante' => $firma_filename,
                
                // Nuevos campos
                'ip_registro' => $ip_address,
                'user_agent' => $user_agent,

                'estado' => 'pendiente_deudor',
                'datos_entrega' => trim($aviso_diciembre) 
            ),
            // Nota: Se agregaron dos '%s' al final del array de formatos
            array( '%d', '%s', '%f', '%s', '%d', '%f', '%f', '%d', '%s', '%s', '%s', '%s' ) 
        );
        $credito_id = $wpdb->insert_id;

        // Notificar eventos automáticos.
        do_action( 'lud_evento_credito_solicitado', $user_id, $credito_id, array(
            'monto'   => $monto,
            'tipo'    => $tipo,
            'plazo'   => $plazo,
            'en_fila' => $en_fila_liquidez
        ) );

        // Notificar Deudor
        $this->enviar_aviso_deudor($deudor_id, $user_id, $monto, $credito_id, $codigo_unico);

        wp_redirect( add_query_arg( 'lud_msg', 'success', wp_get_referer() ) );
        exit;
    }

    // --- 4. ZONA DEL DEUDOR (Sin cambios mayores, solo mantenemos la función) ---
    /**
     * Muestra la pantalla donde el deudor solidario firma y aprueba.
     */
    public function render_zona_deudor() {
        if ( ! is_user_logged_in() ) return 'Inicia sesión.';
        if ( ! isset($_GET['cid']) || ! isset($_GET['token']) ) return 'Enlace inválido.';

        global $wpdb;
        $credito_id = intval($_GET['cid']);
        $token_url = sanitize_text_field($_GET['token']);
        $current_user = get_current_user_id();

        $credito = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE id = $credito_id");

        if ( !$credito ) return 'Crédito no encontrado.';
        if ( $credito->deudor_solidario_id != $current_user ) return 'No eres el deudor asignado.';
        if ( $credito->codigo_seguimiento !== $token_url ) return 'Token de seguridad inválido.';
        if ( $credito->estado != 'pendiente_deudor' ) return 'Esta solicitud ya fue procesada.';

        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header"><h3>Aprobación de Deudor Solidario</h3></div>
            <p>Has sido postulado como fiador para un crédito.</p>
            <ul>
                <li><strong>Solicitante:</strong> <?php echo get_the_author_meta('display_name', $credito->user_id); ?></li>
                <li><strong>Monto Total:</strong> $ <?php echo number_format($credito->monto_solicitado); ?></li>
                <li><strong>Plazo:</strong> <?php echo $credito->plazo_meses; ?> meses</li>
            </ul>
            <p style="background:#e3f2fd; padding:10px; border-radius:5px;">
                Al firmar, aceptas respaldar esta deuda según los estatutos del Fondo La Unión.
            </p>

            <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST">
                <input type="hidden" name="action" value="lud_firmar_deudor">
                <input type="hidden" name="credito_id" value="<?php echo $credito->id; ?>">
                <?php wp_nonce_field( 'lud_deudor_nonce', 'lud_security' ); ?>

                <div class="lud-form-group">
                    <label class="lud-label">Tu Firma de Aprobación</label>
                    <div style="border: 2px dashed #ccc; background:#fff; cursor:crosshair; border-radius:8px;">
                        <canvas id="canvas_deudor" width="400" height="150" style="width:100%; touch-action: none;"></canvas>
                    </div>
                    <button type="button" onclick="limpiarFirmaDeudor()" style="color:#d32f2f; background:none; border:none; cursor:pointer; margin-top:5px;">🗑️ Borrar</button>
                    <input type="hidden" name="firma_deudor_data" id="firma_deudor_data" required>
                </div>

                <button type="submit" class="lud-btn" id="btn_aprobar">✅ Aprobar y Enviar a Tesorería</button>
            </form>
        </div>
        <script>
            var cv = document.getElementById('canvas_deudor');
            var cx = cv.getContext('2d');
            var draw = false;
            cv.addEventListener('mousedown', function(e){ draw=true; cx.beginPath(); cx.moveTo(e.offsetX, e.offsetY); });
            cv.addEventListener('mousemove', function(e){ if(draw){ cx.lineTo(e.offsetX, e.offsetY); cx.stroke(); } });
            cv.addEventListener('mouseup', function(){ draw=false; document.getElementById('firma_deudor_data').value = cv.toDataURL(); });
            function limpiarFirmaDeudor(){ cx.clearRect(0,0,cv.width,cv.height); document.getElementById('firma_deudor_data').value=''; }
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Guarda la firma del deudor solidario y cambia el estado del crédito.
     */
    public function procesar_firma_deudor() {
        if ( ! isset( $_POST['lud_security'] ) || ! wp_verify_nonce( $_POST['lud_security'], 'lud_deudor_nonce' ) ) wp_die('Seguridad');
        
        global $wpdb;
        $credito_id = intval($_POST['credito_id']);
        $firma_base64 = $_POST['firma_deudor_data'];

        $credito = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE id = %d", $credito_id) );
        if ( ! $credito ) {
            wp_die('Crédito no encontrado');
        }

        if ( empty($firma_base64) ) wp_die('Firma requerida');

        $upload_dir = wp_upload_dir();
        $firmas_dir = $upload_dir['basedir'] . '/fondo_seguro/firmas/';
        $firma_filename = 'deudor_' . $credito_id . '_' . time() . '.png';
        
        $data = explode(',', $firma_base64);
        file_put_contents($firmas_dir . $firma_filename, base64_decode($data[1]));

        // Detectar si la solicitud debe ir a la cola por falta de liquidez al momento del envío
        $flag_fila_liquidez = ( strpos( $credito->datos_entrega, '[FILA_LIQUIDEZ]' ) !== false );
        $liquidez = self::get_liquidez_disponible();
        $estado_destino = ( $flag_fila_liquidez && $credito->monto_solicitado > $liquidez ) ? 'fila_liquidez' : 'pendiente_tesoreria';

        $nota_fila = '';
        if ( $flag_fila_liquidez ) {
            if ( $estado_destino === 'fila_liquidez' ) {
                $nota_fila = " | Fila de liquidez activa desde " . current_time('mysql');
            } else {
                $nota_fila = " | Fila liberada por liquidez el " . current_time('mysql');
            }
        }

        $wpdb->update(
            $wpdb->prefix . 'fondo_creditos',
            array( 
                'estado' => $estado_destino, 
                'firma_deudor' => $firma_filename,
                'fecha_aprobacion_deudor' => current_time('mysql'),
                'datos_entrega' => trim( $credito->datos_entrega . $nota_fila )
            ),
            array( 'id' => $credito_id )
        );

        // Intentar liberar otras solicitudes en fila si la liquidez lo permite
        $this->liberar_fila_por_liquidez();

        wp_die('<div style="text-align:center; padding:50px; font-family:sans-serif;"><h1>✅ ¡Gracias!</h1><p>Has aprobado la solicitud. Ahora pasará a Tesorería para su desembolso.</p><a href="javascript:window.close();">Cerrar ventana</a></div>');
    }

    /**
     * Ajusta el ENUM de estado para admitir la cola de liquidez.
     */
    private function asegurar_estado_fila_liquidez() {
        global $wpdb;
        $tabla = "{$wpdb->prefix}fondo_creditos";
        $columna_estado = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM $tabla LIKE %s", 'estado' ) );
        if ( $columna_estado && strpos( $columna_estado->Type, 'fila_liquidez' ) !== false ) {
            return;
        }
        $wpdb->query(
            "ALTER TABLE $tabla MODIFY estado ENUM('pendiente_deudor','pendiente_tesoreria','fila_liquidez','activo','rechazado','pagado','mora') DEFAULT 'pendiente_deudor'"
        );
    }

    /**
     * Libera solicitudes en fila cuando la liquidez permite radicarlas.
     */
    public function liberar_fila_por_liquidez() {
        global $wpdb;
        $creditos_fila = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE estado = 'fila_liquidez' AND firma_deudor IS NOT NULL ORDER BY fecha_solicitud ASC" );
        if ( empty( $creditos_fila ) ) {
            return;
        }

        // Comentario: priorizamos por score de buen pagador y luego por fecha de llegada.
        foreach ( $creditos_fila as $indice => $credito_fila ) {
            $creditos_fila[ $indice ]->score_prioridad = self::calcular_score_socio( $credito_fila->user_id );
        }

        usort( $creditos_fila, function( $a, $b ) {
            if ( $a->score_prioridad == $b->score_prioridad ) {
                return strcmp( $a->fecha_solicitud, $b->fecha_solicitud );
            }
            return ( $a->score_prioridad < $b->score_prioridad ) ? 1 : -1;
        } );

        $liquidez_disponible = self::get_liquidez_disponible();
        foreach ( $creditos_fila as $credito_fila ) {
            if ( $credito_fila->monto_solicitado <= $liquidez_disponible ) {
                $liquidez_disponible -= $credito_fila->monto_solicitado;
                $nota_actualizada = trim( $credito_fila->datos_entrega . " | Fila liberada por liquidez el " . current_time('mysql') );

                // Se promueve la solicitud a Tesorería respetando el orden de llegada y el cupo actualizado
                $wpdb->update(
                    $wpdb->prefix . 'fondo_creditos',
                    array(
                        'estado' => 'pendiente_tesoreria',
                        'datos_entrega' => $nota_actualizada
                    ),
                    array( 'id' => $credito_fila->id )
                );
            }
        }
    }

    // --- HELPERS Y PDF STATIC ---
    
    /**
     * Envía correo al deudor solidario con el enlace de aprobación.
     */
    private function enviar_aviso_deudor($deudor_id, $solicitante_id, $monto, $credito_id, $token) {
        // Delegamos al motor de notificaciones para aplicar la plantilla unificada.
        do_action( 'lud_evento_credito_deudor', $deudor_id, $solicitante_id, $monto, $credito_id, $token );
    }

    /**
     * Verifica si un crédito ya fue producto de una refinanciación previa.
     */
    private function es_credito_refinanciado( $credito_row ) {
        if ( ! $credito_row ) {
            return false;
        }

        $datos_entrega = strtolower( (string) $credito_row->datos_entrega );
        if ( strpos( $datos_entrega, 'refinancia_origen' ) !== false || strpos( $datos_entrega, 'refinanciaci' ) !== false ) {
            return true;
        }

        global $wpdb;
        $referencia = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_creditos WHERE user_id = %d AND datos_entrega LIKE %s",
            $credito_row->user_id,
            '%REFINANCIA_ORIGEN:' . $credito_row->id . '%'
        ) );

        return $referencia > 0;
    }

    /**
     * Genera el paquete de documentos PDF de un crédito aprobado (contrato y pagaré con carta de instrucciones).
     */
    public static function generar_pdf_final_static($credito_row) {
        // Busca TCPDF
        $rutas = [ 
            WP_CONTENT_DIR.'/librerias_compartidas/tcpdf/tcpdf.php',
            WP_CONTENT_DIR.'/librerias_compartidas/TCPDF/tcpdf.php'
        ];
        foreach($rutas as $r) { if(file_exists($r)) { require_once $r; break; } }
        
        if(!class_exists('TCPDF')) return false;

        $user = get_userdata($credito_row->user_id);
        $deudor = get_userdata($credito_row->deudor_solidario_id);
        $upload = wp_upload_dir();
        $firmas_base = $upload['basedir'] . '/fondo_seguro/firmas/';
        $pdf_dir = $upload['basedir'] . '/fondo_seguro/contratos/';
        if (!file_exists($pdf_dir)) mkdir($pdf_dir, 0755, true);

        $datos_doc = self::preparar_datos_documentos( $credito_row, $user, $deudor );
        if ( empty( $datos_doc ) ) {
            return false;
        }

        // --- CONTRATO DE MUTUO ---
        $pdf = new TCPDF();
        $pdf->SetCreator('Fondo La Unión');
        $pdf->SetPrintHeader(false);
        $pdf->AddPage();

        $html = "<h1 style=\"text-align:center;\">Contrato de Mutuo de Dinero No {$datos_doc['numero_contrato']}</h1>
                 <p style=\"text-align:justify;\">Entre <strong>FONDO DE INVERSIÓN LA UNIÓN</strong>, con domicilio en Villa del Rosario, Norte de Santander, quien para efectos de este contrato se denomina <strong>EL FONDO</strong>, por una parte, y por la otra <strong>{$datos_doc['deudor_nombre']}</strong>, identificado(a) con {$datos_doc['deudor_documento']} y domicilio en {$datos_doc['deudor_domicilio']}, quien se denomina <strong>EL DEUDOR</strong>; y <strong>{$datos_doc['fiador_nombre']}</strong>, identificado(a) con {$datos_doc['fiador_documento']} y domicilio en {$datos_doc['fiador_domicilio']}, quien actúa como <strong>DEUDOR SOLIDARIO</strong>, se celebra el presente contrato de mutuo regido por las siguientes cláusulas:</p>
                 <h3>Cláusulas</h3>
                 <ol style=\"text-align:justify; font-size:10pt; line-height:1.4;\">
                    <li><strong>Objeto y desembolso.</strong> EL FONDO entrega en calidad de mutuo a EL DEUDOR la suma de <strong>{$datos_doc['monto_formateado']}</strong> ({$datos_doc['monto_letras']}). El desembolso se realiza conforme a: <em>{$datos_doc['detalle_entrega']}</em>. EL DEUDOR declara haber recibido a satisfacción el capital.</li>
                    <li><strong>Plazo y forma de pago.</strong> EL DEUDOR pagará el capital en <strong>{$datos_doc['plazo_meses']} cuota(s)</strong> iguales de capital más intereses. La primera cuota vencerá el <strong>{$datos_doc['fecha_primera_cuota']}</strong>, de conformidad con el acta del 21 de septiembre de 2024 (pago el día 5 del mes subsiguiente), y las siguientes el día 5 de cada mes, salvo que se acuerde por escrito otra fecha.</li>
                    <li><strong>Interés remuneratorio.</strong> La tasa pactada es de <strong>{$datos_doc['tasa_interes_texto']} mensual</strong>, dentro de los topes legales vigentes. El interés total estimado sobre el capital asciende a <strong>{$datos_doc['interes_total_formateado']}</strong>, para un total esperado de <strong>{$datos_doc['total_general_formateado']}</strong>.</li>
                    <li><strong>Interés de mora.</strong> El retraso en cualquier cuota causará interés moratorio a la <strong>tasa máxima legal permitida</strong> certificada por la Superintendencia Financiera, o a la tasa especial de mora definida en los estatutos si es superior para créditos ágiles, sin superar el tope de usura. El pago de intereses moratorios no implica novación ni renuncia al cobro del capital.</li>
                    <li><strong>Cláusula aceleratoria.</strong> El incumplimiento en el pago de una sola cuota o interés faculta a EL FONDO para declarar vencidas todas las obligaciones y exigir de inmediato el saldo total adeudado, sin necesidad de requerimiento adicional.</li>
                    <li><strong>Aplicación de pagos.</strong> Todo pago se imputará primero a costos judiciales y extrajudiciales (si los hubiere), luego a intereses de mora, intereses corrientes y finalmente a capital. EL FONDO podrá compensar saldos en favor del deudor con obligaciones vencidas.</li>
                    <li><strong>Garantía personal y solidaridad.</strong> El DEUDOR SOLIDARIO responde de manera <strong>solidaria e indivisible</strong> por la totalidad de las obligaciones, capital, intereses y costos de cobro, renunciando a los beneficios de excusión, división y orden.</li>
                    <li><strong>Seguros y autorizaciones.</strong> Las partes autorizan a EL FONDO a reportar el comportamiento de pago a centrales de riesgo, consultar información en bases de datos y conservar copia digital de firmas, huellas y evidencias técnicas de firma.</li>
                    <li><strong>Mérito ejecutivo.</strong> Este contrato y el pagaré anexo constituyen título ejecutivo conforme al Código General del Proceso. La mora se acreditará con certificación de saldo expedida por Tesorería.</li>
                    <li><strong>Notificaciones y domicilio.</strong> Las comunicaciones se enviarán al correo registrado por cada parte en el Fondo o a la dirección física indicada. El domicilio contractual es Villa del Rosario, Norte de Santander.</li>
                    <li><strong>Vigencia y ley aplicable.</strong> El contrato se rige por la ley colombiana y los estatutos del Fondo. Cualquier controversia será competencia de los jueces de Villa del Rosario.</li>
                 </ol>
                 <br>
                 <table cellpadding=\"6\" style=\"font-size:9px; color:#555; border:1px solid #ccc;\">
                    <tr>
                        <td><strong>Datos del crédito</strong><br>
                        Tipo: {$datos_doc['tipo_credito']}<br>
                        Cuota estimada: {$datos_doc['cuota_estimada']}<br>
                        Vencimiento estimado: {$datos_doc['fecha_vencimiento']}<br>
                        Huella digital: IP {$datos_doc['ip']} · Agente {$datos_doc['user_agent']} · Fecha {$datos_doc['fecha_solicitud']}</td>
                    </tr>
                 </table>
                 <br><p>En constancia se firma digitalmente el {$datos_doc['fecha_contrato']}.</p>";
        
        $pdf->writeHTML($html, true, false, true, false, '');

        $y = $pdf->GetY() + 10;
        if($credito_row->firma_solicitante && file_exists($firmas_base.$credito_row->firma_solicitante)) {
            $pdf->Image($firmas_base.$credito_row->firma_solicitante, 15, $y, 50, '', 'PNG');
            $pdf->Text(15, $y+25, 'Firma Solicitante');
        }
        if($credito_row->firma_deudor && file_exists($firmas_base.$credito_row->firma_deudor)) {
            $pdf->Image($firmas_base.$credito_row->firma_deudor, 90, $y, 50, '', 'PNG');
            $pdf->Text(90, $y+25, 'Firma Deudor Solidario');
        }

        $nombre_contrato = "contrato_{$credito_row->id}_{$credito_row->codigo_seguimiento}.pdf";
        $pdf->Output($pdf_dir . $nombre_contrato, 'F');

        // --- PAGARÉ + CARTA DE INSTRUCCIONES ---
        $pagare = new TCPDF();
        $pagare->SetCreator('Fondo La Unión');
        $pagare->SetPrintHeader(false);
        $pagare->AddPage();

        $html_pagare = "<h1 style=\"text-align:center;\">Pagaré No {$datos_doc['numero_contrato']}</h1>
                        <p style=\"text-align:justify;\">Yo, <strong>{$datos_doc['deudor_nombre']}</strong> ({$datos_doc['deudor_documento']}), me obligo a pagar incondicionalmente a la orden de <strong>FONDO DE INVERSIÓN LA UNIÓN</strong>, en Villa del Rosario, la suma de <strong>{$datos_doc['total_general_formateado']}</strong> ({$datos_doc['total_general_letras']}) a más tardar el <strong>{$datos_doc['fecha_vencimiento']}</strong>. El crédito devenga interés remuneratorio del <strong>{$datos_doc['tasa_interes_texto']} mensual</strong> y en mora la tasa máxima legal vigente certificada por la Superintendencia Financiera, o la tasa especial de mora definida en los estatutos para créditos ágiles sin exceder la usura.</p>
                        <p style=\"text-align:justify;\">Faculto a EL FONDO para declarar exigible la totalidad de la deuda por incumplimiento de una cuota (cláusula aceleratoria), sin requerimiento adicional. Renuncio a presentaciones y protestos. Lugar de pago: Villa del Rosario, Norte de Santander.</p>
                        <p style=\"text-align:justify;\">DEUDOR SOLIDARIO: <strong>{$datos_doc['fiador_nombre']}</strong> ({$datos_doc['fiador_documento']}) responde solidariamente por esta obligación, capital e intereses, renunciando a los beneficios de excusión y división.</p>
                        <table cellpadding=\"6\" style=\"font-size:9px; color:#555; border:1px solid #ccc;\">
                            <tr><td><strong>Fecha de desembolso:</strong> {$datos_doc['fecha_contrato']}</td></tr>
                            <tr><td><strong>Vencimiento estimado:</strong> {$datos_doc['fecha_vencimiento']}</td></tr>
                            <tr><td><strong>Valor del pagaré:</strong> {$datos_doc['total_general_formateado']} ({$datos_doc['total_general_letras']})</td></tr>
                        </table>";
        $pagare->writeHTML($html_pagare, true, false, true, false, '');

        $y_pagare = $pagare->GetY() + 10;
        if($credito_row->firma_solicitante && file_exists($firmas_base.$credito_row->firma_solicitante)) {
            $pagare->Image($firmas_base.$credito_row->firma_solicitante, 15, $y_pagare, 50, '', 'PNG');
            $pagare->Text(15, $y_pagare+25, 'Firma Deudor');
        }
        if($credito_row->firma_deudor && file_exists($firmas_base.$credito_row->firma_deudor)) {
            $pagare->Image($firmas_base.$credito_row->firma_deudor, 90, $y_pagare, 50, '', 'PNG');
            $pagare->Text(90, $y_pagare+25, 'Firma Deudor Solidario');
        }

        // Carta de instrucciones
        $pagare->AddPage();
        $html_carta = "<h2 style=\"text-align:center;\">Carta de Instrucciones para Diligenciamiento del Pagaré</h2>
                       <p style=\"text-align:justify;\">Yo, <strong>{$datos_doc['deudor_nombre']}</strong> ({$datos_doc['deudor_documento']}), autorizo irrevocablemente a <strong>FONDO DE INVERSIÓN LA UNIÓN</strong> para que complete los espacios en blanco del Pagaré No {$datos_doc['numero_contrato']} suscrito a su favor, en los siguientes términos:</p>
                       <ol style=\"text-align:justify; font-size:10pt; line-height:1.4;\">
                            <li>El valor a diligenciar corresponderá al saldo insoluto de capital más intereses remuneratorios y de mora causados a la fecha de incumplimiento, sin exceder los topes legales.</li>
                            <li>La fecha de vencimiento que se coloque será la del momento en que EL FONDO declare exigible la totalidad de la obligación por mora o aceleración.</li>
                            <li>Se podrán incluir costos y gastos de cobranza judicial o extrajudicial, de acuerdo con la normatividad colombiana.</li>
                            <li>Autorizo el uso de mis datos personales para la gestión de cobranza y reporte en centrales de riesgo, conforme a la política de tratamiento de datos del Fondo.</li>
                       </ol>
                       <p style=\"text-align:justify;\">Esta carta se firma en la misma fecha de desembolso: {$datos_doc['fecha_contrato']}.</p>";
        $pagare->writeHTML($html_carta, true, false, true, false, '');

        $y_carta = $pagare->GetY() + 10;
        if($credito_row->firma_solicitante && file_exists($firmas_base.$credito_row->firma_solicitante)) {
            $pagare->Image($firmas_base.$credito_row->firma_solicitante, 15, $y_carta, 50, '', 'PNG');
            $pagare->Text(15, $y_carta+25, 'Firma Deudor');
        }
        if($credito_row->firma_deudor && file_exists($firmas_base.$credito_row->firma_deudor)) {
            $pagare->Image($firmas_base.$credito_row->firma_deudor, 90, $y_carta, 50, '', 'PNG');
            $pagare->Text(90, $y_carta+25, 'Firma Deudor Solidario');
        }

        $nombre_pagare = "pagare_{$credito_row->id}_{$credito_row->codigo_seguimiento}.pdf";
        $pagare->Output($pdf_dir . $nombre_pagare, 'F');

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'fondo_creditos',
            array(
                'contrato_pdf' => $nombre_contrato,
                'pagare_pdf'   => $nombre_pagare
            ),
            array( 'id' => $credito_row->id )
        );

        return array(
            'contrato' => $nombre_contrato,
            'pagare'   => $nombre_pagare
        );
    }

    /**
     * Asegura las columnas de contratos y pagares en la tabla de créditos.
     */
    private function asegurar_columnas_documentos() {
        global $wpdb;
        $tabla = $wpdb->prefix . 'fondo_creditos';
        $col_contrato = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $tabla LIKE %s", 'contrato_pdf' ) );
        $col_pagare   = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $tabla LIKE %s", 'pagare_pdf' ) );

        if ( ! $col_contrato ) {
            $wpdb->query( "ALTER TABLE $tabla ADD contrato_pdf VARCHAR(255) NULL AFTER datos_entrega" );
        }
        if ( ! $col_pagare ) {
            $wpdb->query( "ALTER TABLE $tabla ADD pagare_pdf VARCHAR(255) NULL AFTER contrato_pdf" );
        }
    }

    /**
     * Prepara datos formateados y consistentes para los documentos legales del crédito.
     */
    private static function preparar_datos_documentos( $credito_row, $user, $deudor ) {
        global $wpdb;
        if ( ! $credito_row || ! $user || ! $deudor ) {
            return array();
        }

        $cuenta_deudor = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d",
            $credito_row->user_id
        ) );
        $cuenta_fiador = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d",
            $credito_row->deudor_solidario_id
        ) );

        $monto = floatval( $credito_row->monto_aprobado ?: $credito_row->monto_solicitado );
        $plazo = intval( $credito_row->plazo_meses );
        $tasa = floatval( $credito_row->tasa_interes );
        $fecha_inicio = $credito_row->fecha_aprobacion ?: $credito_row->fecha_solicitud;
        if ( ! $fecha_inicio ) {
            $fecha_inicio = current_time( 'mysql' );
        }

        $resultado_amortizacion = LUD_Amortizacion::construir_tabla_amortizacion(
            $monto,
            $tasa,
            $plazo,
            $fecha_inicio,
            $credito_row->tipo_credito
        );
        $resumen_amortizacion = $resultado_amortizacion['resumen'] ?? array();
        $interes_total = $resumen_amortizacion['interes_total'] ?? 0;
        $total_general = $resumen_amortizacion['total_general'] ?? 0;
        $fecha_primera = $resumen_amortizacion['fecha_primera'] ?? '';
        $fecha_vencimiento = $resumen_amortizacion['fecha_vencimiento'] ?? '';
        $cuota_estimada = $resumen_amortizacion['cuota_inicial'] ?? 0;

        $fecha_primera_formateada = $fecha_primera
            ? date_i18n( 'd/m/Y', strtotime( $fecha_primera ) )
            : date_i18n( 'd/m/Y' );
        $fecha_vencimiento_formateada = $fecha_vencimiento
            ? date_i18n( 'd/m/Y', strtotime( $fecha_vencimiento ) )
            : date_i18n( 'd/m/Y' );
        $fecha_contrato = $fecha_inicio
            ? date_i18n( 'd/m/Y', strtotime( $fecha_inicio ) )
            : date_i18n( 'd/m/Y' );

        $monto_letras = self::convertir_numero_a_letras( $monto );
        $total_letras = self::convertir_numero_a_letras( $total_general );

        return array(
            'numero_contrato'        => $credito_row->id,
            'deudor_nombre'          => $user->display_name,
            'deudor_documento'       => trim( ( $cuenta_deudor ? $cuenta_deudor->tipo_documento : '' ) . ' ' . ( $cuenta_deudor ? $cuenta_deudor->numero_documento : '' ) ),
            'deudor_domicilio'       => $cuenta_deudor && $cuenta_deudor->ciudad_pais ? $cuenta_deudor->ciudad_pais : 'Villa del Rosario, Norte de Santander',
            'fiador_nombre'          => $deudor->display_name,
            'fiador_documento'       => trim( ( $cuenta_fiador ? $cuenta_fiador->tipo_documento : '' ) . ' ' . ( $cuenta_fiador ? $cuenta_fiador->numero_documento : '' ) ),
            'fiador_domicilio'       => $cuenta_fiador && $cuenta_fiador->ciudad_pais ? $cuenta_fiador->ciudad_pais : 'Villa del Rosario, Norte de Santander',
            'monto_formateado'       => '$' . number_format( $monto, 0, ',', '.' ),
            'monto_letras'           => $monto_letras,
            'interes_total_formateado' => '$' . number_format( $interes_total, 0, ',', '.' ),
            'total_general_formateado' => '$' . number_format( $total_general, 0, ',', '.' ),
            'total_general_letras'   => $total_letras,
            'plazo_meses'            => $plazo,
            'tasa_interes_texto'     => number_format( $tasa, 2 ) . '%',
            'detalle_entrega'        => $credito_row->datos_entrega ?: 'Entrega según instrucciones de Tesorería',
            'fecha_primera_cuota'    => $fecha_primera_formateada,
            'fecha_vencimiento'      => $fecha_vencimiento_formateada,
            'tipo_credito'           => ucfirst( $credito_row->tipo_credito ),
            'cuota_estimada'         => '$' . number_format( $cuota_estimada ?: $credito_row->cuota_estimada, 0, ',', '.' ),
            'ip'                     => $credito_row->ip_registro,
            'user_agent'             => $credito_row->user_agent,
            'fecha_solicitud'        => $credito_row->fecha_solicitud,
            'fecha_contrato'         => $fecha_contrato,
            'total_general'          => $total_general
        );
    }

    /**
     * Convierte un número a letras en español (formato pesos).
     */
    private static function convertir_numero_a_letras( $numero ) {
        $entero = floor( $numero );
        $decimales = round( ( $numero - $entero ) * 100 );

        $unidades = array('', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte');
        $decenas = array('', '', 'veinti', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa');
        $centenas = array('', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos');

        $palabras = function( $n ) use ( $unidades, $decenas, $centenas, &$palabras ) {
            if ( $n == 0 ) return 'cero';
            if ( $n == 100 ) return 'cien';
            $texto = '';
            if ( $n >= 100 ) {
                $texto .= $centenas[ intval( $n / 100 ) ] . ' ';
                $n = $n % 100;
            }
            if ( $n <= 20 ) {
                $texto .= $unidades[ $n ];
            } else {
                $texto .= $decenas[ intval( $n / 10 ) ];
                $unidad = $n % 10;
                if ( $unidad > 0 ) {
                    $texto .= ( $n < 30 ? '' : ' y ' ) . $unidades[ $unidad ];
                }
            }
            return trim( $texto );
        };

        $convertir_miles = function( $n ) use ( $palabras ) {
            if ( $n < 1000 ) return $palabras( $n );
            if ( $n < 1000000 ) {
                $miles = intval( $n / 1000 );
                $resto = $n % 1000;
                $prefijo = $miles == 1 ? 'mil' : $palabras( $miles ) . ' mil';
                return trim( $prefijo . ' ' . ( $resto ? $palabras( $resto ) : '' ) );
            }
            $millones = intval( $n / 1000000 );
            $resto = $n % 1000000;
            $prefijo = $millones == 1 ? 'un millón' : $palabras( $millones ) . ' millones';
            return trim( $prefijo . ' ' . ( $resto ? $palabras( $resto ) : '' ) );
        };

        $texto_entero = $convertir_miles( $entero );
        $texto_centavos = str_pad( $decimales, 2, '0', STR_PAD_LEFT );

        return strtoupper( $texto_entero . ' pesos M/CTE (' . $texto_centavos . '/100)' );
    }
}
