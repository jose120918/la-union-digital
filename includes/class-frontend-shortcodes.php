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
        
        // --- 1. CÁLCULO DEUDA ---
        $debe_ahorro = 0; $debe_secretaria = 0; $debe_multa = 0;
        $detalle_mora = []; // Comentario: lista de periodos en mora con días y valor.
        $fecha_ultimo = $datos->fecha_ultimo_aporte ? $datos->fecha_ultimo_aporte : date('Y-m-01'); 
        $inicio = new DateTime( $fecha_ultimo );
        $inicio->modify( 'first day of next month' ); 
        $hoy = new DateTime();
        $cursor = clone $inicio;
        while ( $cursor <= $hoy ) {
            $debe_ahorro += $valor_cuota_ahorro;
            $debe_secretaria += $valor_cuota_secretaria;
            $limite_mes = clone $cursor;
            $limite_mes->setDate( $cursor->format('Y'), $cursor->format('m'), 5 );
            if ( $hoy > $limite_mes ) {
                $dias_tarde = $hoy->diff( $limite_mes )->days;
                $debe_multa += ($dias_tarde * 1000 * $acciones);
                $detalle_mora[] = array(
                    'periodo' => $cursor->format('F Y'),
                    'dias'    => $dias_tarde,
                    'monto'   => $dias_tarde * 1000 * $acciones
                );
            }
            $cursor->modify( 'first day of next month' );
        }
        $total_pendiente = $debe_ahorro + $debe_secretaria + $debe_multa;
        $total_dias_mora = array_sum( array_map( function( $m ){ return $m['dias']; }, $detalle_mora ) );
        $conteo_meses_mora = count( $detalle_mora );

        // --- 2. CÁLCULO RENDIMIENTOS DINÁMICO ---
        $anio_actual = date('Y');
        $acumulado_este_anio = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(utilidad_asignada) FROM {$wpdb->prefix}fondo_utilidades_mensuales 
             WHERE user_id = %d AND anio = %d", 
            $user_id, $anio_actual
        ));
        
        $rendimientos_totales = floatval($datos->saldo_rendimientos) + floatval($acumulado_este_anio);
        $ahorro_total = number_format( $datos->saldo_ahorro_capital, 0, ',', '.' );
        
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
            <div class="lud-debt-box">
                <h4>⚠️ Tienes pagos en mora (<?php echo $conteo_meses_mora; ?> periodo<?php echo $conteo_meses_mora != 1 ? 's' : ''; ?> / <?php echo $total_dias_mora; ?> día<?php echo $total_dias_mora != 1 ? 's' : ''; ?>)</h4>
                <div class="lud-debt-row"><span>Ahorro:</span><span>$ <?php echo number_format($debe_ahorro); ?></span></div>
                <div class="lud-debt-row"><span>Secretaría:</span><span>$ <?php echo number_format($debe_secretaria); ?></span></div>
                <?php if ($debe_multa > 0): ?><div class="lud-debt-row"><span>Mora:</span><span>$ <?php echo number_format($debe_multa); ?></span></div><?php endif; ?>
                <?php if ( !empty($detalle_mora) ): ?>
                    <div style="margin-top:10px; font-size:0.85rem; color:#8d6e63;">
                        <strong>Detalle de periodos en mora:</strong>
                        <ul style="margin:6px 0 0 16px; padding:0;">
                            <?php foreach ( $detalle_mora as $mora ): ?>
                                <li><?php echo esc_html( $mora['periodo'] ); ?> · <?php echo $mora['dias']; ?> día<?php echo $mora['dias'] != 1 ? 's' : ''; ?> · $<?php echo number_format( $mora['monto'] ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <div class="lud-debt-total"><span>Total a Pagar:</span><span>$ <?php echo number_format($total_pendiente); ?></span></div>
            </div>
            <?php else: ?>
            <div class="lud-success-box">✅ Estás al día con tus aportes.</div>
            <?php endif; ?>

            <div class="lud-details-grid">
                <div class="lud-detail-item">
                    <strong>Mis Acciones Hoy</strong>
                    <span><?php echo $acciones; ?></span>
                </div>
                <div class="lud-detail-item" style="position:relative;">
                    <strong>Rendimientos <?php echo $anio_actual; ?></strong>
                    <span style="color:#1565c0;">$ <?php echo number_format($rendimientos_totales, 0, ',', '.'); ?></span>
                    <?php if($acumulado_este_anio > 0): ?>
                        <small style="display:block; font-size:0.7rem; color:#888;">(Incluye $<?php echo number_format($acumulado_este_anio); ?> acumulados este año)</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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
            <?php if ( empty($movimientos) ): ?>
                <div style="text-align:center; padding:20px; color:#777; background:#fafafa; border-radius:8px;">
                    <span style="font-size:2rem;">📭</span><br>No hay movimientos registrados aún.
                </div>
            <?php else: ?>
                <table class="lud-table">
                    <thead><tr><th>Fecha</th><th>Concepto</th><th>Monto</th><th>Estado</th><th>Detalle</th><th>Acciones</th></tr></thead>
                    <tbody id="historial_body">
                        <?php foreach($movimientos as $m): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($m->fecha_registro)); ?></td>
                            <td><?php echo esc_html( $this->obtener_concepto_legible( $m ) ); ?></td>
                            <td>$ <?php echo number_format($m->monto); ?></td>
                            <td><?php echo ucfirst($m->estado); ?></td>
                            <td><?php echo $m->detalle; ?></td>
                            <td>
                                <?php if ( !empty($m->comprobante_url) ): ?>
                                    <?php $upload = wp_upload_dir(); ?>
                                    <a href="<?php echo esc_url( trailingslashit($upload['baseurl']) . 'fondo_seguro/' . $m->comprobante_url ); ?>" target="_blank" class="lud-btn" style="width:auto; padding:6px 10px; font-size:0.85rem;">Ver</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ( $hay_mas ): ?>
                    <button class="lud-btn" id="btn_cargar_mas" style="margin-top:10px; width:100%; background:#eee; color:#444; border:1px solid #ddd; box-shadow:none;">Cargar más</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <script>
            (function(){
                const ajaxUrl = "<?php echo admin_url('admin-ajax.php'); ?>";
                const nonce = "<?php echo $nonce_hist; ?>";
                let offset = <?php echo $limite; ?>;
                const perPage = <?php echo $limite; ?>;

                function renderRows(rowsHtml, append=true){
                    const tbody = document.getElementById('historial_body');
                    if (!tbody) return;
                    if (!append) tbody.innerHTML = '';
                    tbody.insertAdjacentHTML( append ? 'beforeend' : 'afterbegin', rowsHtml );
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
            })();
        </script>
        <?php
        return ob_get_clean();
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
            $upload = wp_upload_dir();
            $link = '';
            if ( !empty($m->comprobante_url) ) {
                $link = '<a href="'.esc_url( trailingslashit($upload['baseurl']) . 'fondo_seguro/' . $m->comprobante_url ).'" target=\"_blank\" class=\"lud-btn\" style=\"width:auto; padding:6px 10px; font-size:0.85rem;\">Ver</a>';
            } else {
                $link = '-';
            }
            ?>
            <tr>
                <td><?php echo date('d/m/Y', strtotime($m->fecha_registro)); ?></td>
                <td><?php echo esc_html( $this->obtener_concepto_legible( $m ) ); ?></td>
                <td>$ <?php echo number_format($m->monto); ?></td>
                <td><?php echo ucfirst($m->estado); ?></td>
                <td><?php echo $m->detalle; ?></td>
                <td><?php echo $link; ?></td>
            </tr>
            <?php
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
        global $wpdb;
        $cupos_ocupados = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_cuentas WHERE estado_socio = 'activo'" ) );
        if ( $cupos_ocupados >= 36 ) {
            return '<div class="lud-card" style="text-align:center;">🛑 <strong>Cupos llenos</strong><br>Actualmente hay 36 socios activos según los estatutos. Vuelve a intentarlo cuando se libere un cupo.</div>';
        }
        
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

        global $wpdb;
        $cupos_ocupados = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_cuentas WHERE estado_socio = 'activo'" ) );
        if ( $cupos_ocupados >= 36 ) {
            wp_redirect( add_query_arg( 'lud_err', 'Cupos llenos', wp_get_referer() ) ); exit;
        }

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
