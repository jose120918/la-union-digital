<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Admin_Tesoreria {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        
        // Hooks de Procesamiento
        add_action( 'admin_post_lud_aprobar_pago', array( $this, 'procesar_aprobacion' ) );
        add_action( 'admin_post_lud_rechazar_pago', array( $this, 'procesar_rechazo' ) );
        add_action( 'admin_post_lud_aprobar_desembolso', array( $this, 'procesar_desembolso' ) );
        add_action( 'admin_post_lud_cierre_mensual', array( $this, 'ejecutar_cierre_mensual_manual' ) );
        add_action( 'admin_post_lud_liquidacion_anual', array( $this, 'procesar_liquidacion_anual' ) );
    }

    public function register_menu() {
        // Usamos 'lud_view_tesoreria' para que Secretaria, Presidente y Tesorero puedan ver
        add_menu_page( 'Tesorería', '💰 Tesorería', 'lud_view_tesoreria', 'lud-tesoreria', array( $this, 'router_views' ), 'dashicons-money-alt', 2 );
    }

    /**
     * ENRUTADOR DE VISTAS
     */
    public function router_views() {
        $view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
        
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline" style="margin-bottom:20px;">Gestión de Tesorería La Unión</h1>';
        
        $active_dash = ($view == 'dashboard') ? 'nav-tab-active' : '';
        $active_socio = ($view == 'buscar_socio' || $view == 'detalle_socio') ? 'nav-tab-active' : '';
        
        echo '<nav class="nav-tab-wrapper">';
        echo '<a href="?page=lud-tesoreria&view=dashboard" class="nav-tab '.$active_dash.'" title="Ayuda: Aquí ves el dinero total, apruebas pagos y desembolsas créditos.">📊 Tablero Principal</a>';
        echo '<a href="?page=lud-tesoreria&view=buscar_socio" class="nav-tab '.$active_socio.'" title="Ayuda: Aquí buscas a un socio para ver su historia completa.">👥 Directorio y Consultas</a>';
        echo '</nav>';
        echo '<br>';

        if ( $view == 'dashboard' ) {
            $this->render_dashboard_general();
        } elseif ( $view == 'buscar_socio' ) {
            $this->render_buscador_socios();
        } elseif ( $view == 'detalle_socio' ) {
            $this->render_hoja_vida_socio();
        }
        echo '</div>';
    }

    // --- VISTA 1: TABLERO GENERAL ---
    private function render_dashboard_general() {
        global $wpdb;
        $anio_actual = date('Y');
        
        // SEGURIDAD: Definir si el usuario puede editar (Tesorero/Admin/Presidente)
        $puede_editar = current_user_can('lud_manage_tesoreria');

        // 1. CÁLCULOS
        $total_entradas = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle");
        $total_gastos = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos");
        $total_prestado = $wpdb->get_var("SELECT SUM(monto_aprobado) FROM {$wpdb->prefix}fondo_creditos WHERE estado IN ('activo', 'pagado', 'mora')");
        $dinero_fisico = floatval($total_entradas) - floatval($total_gastos) - floatval($total_prestado);

        $recaudo_sec = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE concepto = 'cuota_secretaria'");
        $gasto_sec = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE categoria = 'secretaria'");
        $fondo_secretaria = floatval($recaudo_sec) - floatval($gasto_sec);
        
        $disponible_para_creditos = $dinero_fisico - $fondo_secretaria;

        // 2. CONSULTAS PENDIENTES
        $pendientes = $wpdb->get_results( 
            "SELECT tx.*, u.user_email, u.display_name 
             FROM {$wpdb->prefix}fondo_transacciones tx
             JOIN {$wpdb->users} u ON tx.user_id = u.ID
             WHERE tx.estado = 'pendiente' ORDER BY tx.fecha_registro ASC"
        );
        
        $creditos_pendientes = $wpdb->get_results(
            "SELECT c.*, u.display_name FROM {$wpdb->prefix}fondo_creditos c
             JOIN {$wpdb->users} u ON c.user_id = u.ID
             WHERE c.estado = 'pendiente_tesoreria'"
        );
        ?>
        
        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:30px;">
            <div class="lud-card" style="flex:1; min-width:300px; background:#2c3e50; color:#fff; cursor:help;" 
                 title="AYUDA: Esta es la suma de todo el dinero que debería haber físicamente en la Caja Fuerte. Si el conteo físico dice otra cosa, hay un descuadre.">
                <h3 style="color:#bdc3c7; margin-top:0;">🏦 Dinero Total en Caja</h3>
                <div style="font-size:2.5rem; font-weight:bold;">$ <?php echo number_format($dinero_fisico, 0, ',', '.'); ?></div>
                <p style="opacity:0.8;">Arqueo físico total (Incluye Secretaría).</p>
            </div>
            
            <div class="lud-card" style="flex:1; min-width:300px; background:#27ae60; color:#fff; cursor:help;"
                 title="AYUDA: Este es el dinero que REALMENTE puedes prestar. El sistema ya restó automáticamente la plata que es de la Secretaría, para que no la toques.">
                <h3 style="color:#a9dfbf; margin-top:0;">✅ Disponible para Prestar</h3>
                <div style="font-size:2.5rem; font-weight:bold;">$ <?php echo number_format($disponible_para_creditos, 0, ',', '.'); ?></div>
                <p style="opacity:0.8;">(Descontando $<?php echo number_format($fondo_secretaria); ?> de Secretaría)</p>
            </div>
        </div>

        <?php if ( $puede_editar ): ?>
        <div class="lud-card" style="border-left: 5px solid #e67e22; margin-bottom:30px;">
            <h3 title="AYUDA: Herramientas para fin de mes.">⚙️ Acciones Administrativas</h3>
            <div style="display:flex; gap:10px;">
                <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('¿Ejecutar cálculo de utilidades de este mes?');">
                    <input type="hidden" name="action" value="lud_cierre_mensual">
                    <button class="button button-large" title="AYUDA: Presiona este botón el último día del mes. El sistema calculará cuánto ganó cada socio este mes.">
                        📅 Ejecutar Cierre Mensual
                    </button>
                </form>
                
                <?php if(date('m') == '12'): ?>
                <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('ATENCIÓN: Esto repartirá el dinero a las cuentas. ¿Seguro?');">
                    <input type="hidden" name="action" value="lud_liquidacion_anual">
                    <button class="button button-primary button-large" title="IMPORTANTE: Este botón solo se usa en Diciembre. Pasa las ganancias del año a los ahorros de los socios.">
                        🎄 Liquidación Anual
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
            
            <div class="lud-card">
                <h3 title="AYUDA: Aquí aparecen los socios que dicen haber entregado dinero. Debes verificar el efectivo antes de aprobar.">📥 Pagos por Aprobar</h3>
                <?php if ( empty($pendientes) ): ?>
                    <p style="color:#27ae60;">✅ Todo al día. No hay pagos pendientes.</p>
                <?php else: ?>
                    <table class="widefat striped">
                        <?php foreach($pendientes as $tx): ?>
                        <tr>
                            <td>
                                <strong><?php echo $tx->display_name; ?></strong><br>
                                <span style="color:#2980b9; font-weight:bold; font-size:1.1em;">$ <?php echo number_format($tx->monto); ?></span><br>
                                <small><?php echo date_i18n('d/M', strtotime($tx->fecha_registro)); ?></small>
                            </td>
                            <td style="text-align:right;">
                                <a href="<?php echo admin_url('admin-post.php?action=lud_ver_comprobante&file='.$tx->comprobante_url); ?>" target="_blank" class="button" title="Clic para ver la foto del recibo que subió el socio.">Ver Foto</a>
                                
                                <div style="margin-top:5px;">
                                    <?php if ( $puede_editar ): ?>
                                        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="lud_aprobar_pago">
                                            <input type="hidden" name="tx_id" value="<?php echo $tx->id; ?>">
                                            <?php wp_nonce_field('aprobar_'.$tx->id, 'security'); ?>
                                            <button class="button button-primary" title="AYUDA: Clic aquí si YA RECIBISTE el dinero en efectivo. Esto suma el saldo al socio.">Aprobar</button>
                                        </form>
                                        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;" onsubmit="return confirm('¿Rechazar pago?');">
                                            <input type="hidden" name="action" value="lud_rechazar_pago">
                                            <input type="hidden" name="tx_id" value="<?php echo $tx->id; ?>">
                                            <input type="hidden" name="motivo" value="No validado">
                                            <?php wp_nonce_field('rechazar_'.$tx->id, 'security'); ?>
                                            <button class="button button-link-delete" style="color:#c0392b;" title="AYUDA: Clic aquí si el dinero no llegó a la caja.">Rechazar</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-lock" title="No tienes permiso para aprobar."></span> <small>Solo Lectura</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <div class="lud-card">
                <h3 title="AYUDA: Aquí aparecen los créditos que ya fueron firmados por Socio y Fiador. Solo falta que entregues el dinero de la caja.">💸 Créditos para Desembolsar</h3>
                <?php if ( empty($creditos_pendientes) ): ?>
                    <p style="color:#7f8c8d;">No hay solicitudes pendientes.</p>
                <?php else: ?>
                    <?php foreach($creditos_pendientes as $c): ?>
                    <div style="border:1px solid #ddd; padding:10px; margin-bottom:10px; border-radius:5px;">
                        <strong><?php echo $c->display_name; ?></strong> solicita <strong style="color:#c0392b;">$<?php echo number_format($c->monto_solicitado); ?></strong>
                        <p style="font-size:0.9em; margin:5px 0;">Plazo: <?php echo $c->plazo_meses; ?> meses | Firmas: ✅ Completas</p>
                        
                        <?php if ( $puede_editar ): ?>
                            <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('¿Confirmas el desembolso?');">
                                <input type="hidden" name="action" value="lud_aprobar_desembolso">
                                <input type="hidden" name="credito_id" value="<?php echo $c->id; ?>">
                                <input type="text" name="datos_entrega" placeholder="Ref. de entrega / Firma" required style="width:100%; margin-bottom:5px;" title="Escribe aquí algún detalle de la entrega del efectivo.">
                                <?php wp_nonce_field('lud_approve_credit', 'security'); ?>
                                <button class="button button-primary" style="width:100%;" title="AYUDA: Dale clic SOLO cuando ya le hayas entregado el dinero físico al socio.">Confirmar Desembolso</button>
                            </form>
                        <?php else: ?>
                            <div style="color:#777; font-style:italic; background:#f0f0f0; padding:5px; text-align:center;">⚠️ Esperando aprobación de Tesorería</div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // --- VISTA 2: BUSCADOR DE SOCIOS ---
    private function render_buscador_socios() {
        global $wpdb;
        $search = isset($_POST['s_socio']) ? sanitize_text_field($_POST['s_socio']) : '';
        
        $socios = [];
        if ( !empty($search) ) {
            $socios = $wpdb->get_results( $wpdb->prepare(
                "SELECT u.ID, u.display_name, u.user_email, c.numero_acciones, c.estado_socio 
                 FROM {$wpdb->users} u 
                 JOIN {$wpdb->prefix}fondo_cuentas c ON u.ID = c.user_id 
                 WHERE u.display_name LIKE %s OR u.user_email LIKE %s",
                '%' . $search . '%', '%' . $search . '%'
            ));
        } else {
            $socios = $wpdb->get_results("SELECT u.ID, u.display_name, u.user_email, c.numero_acciones, c.estado_socio FROM {$wpdb->users} u JOIN {$wpdb->prefix}fondo_cuentas c ON u.ID = c.user_id LIMIT 10");
        }
        ?>
        <div class="lud-card" style="max-width:800px; margin:0 auto;">
            <h3 title="AYUDA: Usa esta pantalla para ver cuánto debe o cuánto tiene ahorrado un socio específico.">🔍 Buscar Asociado</h3>
            <form method="POST">
                <input type="text" name="s_socio" value="<?php echo esc_attr($search); ?>" placeholder="Escribe nombre o correo..." style="width:70%; padding:8px; font-size:16px;">
                <button class="button button-primary button-hero" title="Clic para buscar">Buscar</button>
            </form>
            
            <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead><tr><th>Nombre</th><th>Acciones</th><th>Estado</th><th>Acción</th></tr></thead>
                <tbody>
                <?php if(empty($socios)): ?>
                    <tr><td colspan="4">No se encontraron resultados.</td></tr>
                <?php else: ?>
                    <?php foreach($socios as $s): ?>
                    <tr>
                        <td>
                            <strong><?php echo $s->display_name; ?></strong><br>
                            <small><?php echo $s->user_email; ?></small>
                        </td>
                        <td><?php echo $s->numero_acciones; ?></td>
                        <td>
                            <?php 
                            $color = ($s->estado_socio == 'activo') ? '#27ae60' : '#c0392b';
                            echo "<span style='color:$color; font-weight:bold;'>".ucfirst($s->estado_socio)."</span>";
                            ?>
                        </td>
                        <td>
                            <a href="?page=lud-tesoreria&view=detalle_socio&id=<?php echo $s->ID; ?>" class="button button-primary" title="Clic para ver todos los detalles financieros de este socio.">Ver Hoja de Vida</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // --- VISTA 3: HOJA DE VIDA DETALLADA ---
    private function render_hoja_vida_socio() {
        if ( !isset($_GET['id']) ) return;
        $user_id = intval($_GET['id']);
        
        global $wpdb;
        $user = get_userdata($user_id);
        $cuenta = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = $user_id");
        
        $transacciones = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fondo_transacciones WHERE user_id = $user_id ORDER BY fecha_registro DESC LIMIT 10");
        $creditos = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE user_id = $user_id ORDER BY fecha_solicitud DESC");
        
        $tx_module = new LUD_Module_Transacciones(); 
        $deuda_info = $tx_module->calcular_deuda_usuario($user_id);
        ?>
        
        <p><a href="?page=lud-tesoreria&view=buscar_socio" class="button">← Volver al Directorio</a></p>
        
        <div style="display:flex; gap:20px; flex-wrap:wrap;">
            <div class="lud-card" style="flex:1; min-width:300px;">
                <h3>👤 <?php echo $user->display_name; ?></h3>
                <table style="width:100%; text-align:left;">
                    <tr><th>Acciones:</th><td><?php echo $cuenta->numero_acciones; ?></td></tr>
                    <tr><th>Estado:</th><td><?php echo ucfirst($cuenta->estado_socio); ?></td></tr>
                    <tr><th>Beneficiario:</th><td><?php echo $cuenta->beneficiario_nombre; ?></td></tr>
                    <tr><th>Contacto Ben:</th><td><?php echo isset($cuenta->beneficiario_telefono) ? $cuenta->beneficiario_telefono : '-'; ?></td></tr>
                </table>
            </div>

            <div class="lud-card" style="flex:1; min-width:300px; background:#f9f9f9;">
                <h3>💰 Estado de Cuenta</h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div style="background:#fff; padding:10px; border-radius:5px;" title="Dinero que el socio ha aportado mes a mes.">
                        <small>Total Ahorrado</small><br>
                        <strong style="font-size:1.4em; color:#27ae60;">$ <?php echo number_format($cuenta->saldo_ahorro_capital); ?></strong>
                    </div>
                    <div style="background:#fff; padding:10px; border-radius:5px;" title="Ganancias generadas por los intereses.">
                        <small>Rendimientos</small><br>
                        <strong style="font-size:1.4em; color:#2980b9;">$ <?php echo number_format($cuenta->saldo_rendimientos); ?></strong>
                    </div>
                    <div style="background:#fff; padding:10px; border-radius:5px; grid-column: span 2;" title="Lo que debe hoy (Cuotas atrasadas + Saldo de Créditos)">
                        <small>Deuda Total (Admin + Créditos)</small><br>
                        <?php $total_deuda = $deuda_info['total_admin'] + $deuda_info['creditos']; ?>
                        <strong style="font-size:1.4em; color:<?php echo ($total_deuda > 0) ? '#c0392b' : '#7f8c8d'; ?>;">
                            $ <?php echo number_format($total_deuda); ?>
                        </strong>
                        <?php if($deuda_info['multa'] > 0) echo "<br><small style='color:#e74c3c'>(Incluye mora: $ ".number_format($deuda_info['multa']).")</small>"; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="lud-card" style="margin-top:20px;">
            <h3>📜 Historial de Créditos</h3>
            <?php if(empty($creditos)): ?>
                <p>No ha solicitado créditos.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th>Fecha</th><th>Monto</th><th>Estado</th><th>Saldo Restante</th></tr></thead>
                    <?php foreach($creditos as $c): ?>
                    <tr>
                        <td><?php echo date('d/M/Y', strtotime($c->fecha_solicitud)); ?></td>
                        <td>$ <?php echo number_format($c->monto_solicitado); ?></td>
                        <td><?php echo strtoupper($c->estado); ?></td>
                        <td>$ <?php echo number_format($c->saldo_actual); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="lud-card" style="margin-top:20px;">
            <h3>🧾 Últimas Transacciones</h3>
            <table class="widefat striped">
                <thead><tr><th>Fecha</th><th>Monto</th><th>Estado</th><th>Detalle</th></tr></thead>
                <?php foreach($transacciones as $t): ?>
                <tr>
                    <td><?php echo date('d/M/Y', strtotime($t->fecha_registro)); ?></td>
                    <td>$ <?php echo number_format($t->monto); ?></td>
                    <td><?php echo ucfirst($t->estado); ?></td>
                    <td><?php echo $t->detalle; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }

    // =============================================================
    // LOGICA DE NEGOCIO (SIN CAMBIOS)
    // =============================================================

    public function procesar_aprobacion() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Acceso denegado: No tienes permisos de Tesorero.');
        $tx_id = intval( $_POST['tx_id'] );
        if ( ! wp_verify_nonce( $_POST['security'], 'aprobar_' . $tx_id ) ) wp_die('Seguridad fallida');

        global $wpdb;
        $tx = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}fondo_transacciones WHERE id = $tx_id" );
        if ( ! $tx || $tx->estado != 'pendiente' ) wp_die('Transacción inválida');

        $dinero_disponible = floatval( $tx->monto );
        $log_distribucion = [];
        
        $cuenta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = {$tx->user_id}" );
        $acciones = $cuenta ? intval($cuenta->numero_acciones) : 1;
        $valor_cuota_ahorro = $acciones * 50000;
        $valor_cuota_secretaria = $acciones * 1000;

        $wpdb->query('START TRANSACTION');
        try {
            $fecha_reporte = new DateTime( $tx->fecha_registro );
            $fecha_ultimo = $cuenta->fecha_ultimo_aporte ? $cuenta->fecha_ultimo_aporte : date('Y-m-01');
            $inicio = new DateTime( $fecha_ultimo );
            $inicio->modify( 'first day of next month' );
            
            $debe_ahorro = 0; $debe_secretaria = 0; $debe_multa = 0;

            $cursor = clone $inicio;
            while ( $cursor <= $fecha_reporte ) {
                $debe_ahorro += $valor_cuota_ahorro;
                $debe_secretaria += $valor_cuota_secretaria;
                $limite = clone $cursor;
                $limite->setDate( $cursor->format('Y'), $cursor->format('m'), 5 ); 
                if ( $fecha_reporte > $limite ) {
                    $dias = $fecha_reporte->diff($limite)->days;
                    $debe_multa += ($dias * 1000 * $acciones);
                }
                $cursor->modify( 'first day of next month' );
            }

            $table_recaudos = $wpdb->prefix . 'fondo_recaudos_detalle';
            
            if ( $debe_multa > 0 && $dinero_disponible > 0 ) {
                $cobrado = min( $dinero_disponible, $debe_multa );
                $dinero_disponible -= $cobrado;
                $log_distribucion[] = "Multas: -$$cobrado";
                $wpdb->insert( $table_recaudos, ['transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 'concepto' => 'multa', 'monto' => $cobrado, 'fecha_recaudo' => current_time('mysql')] );
            }

            if ( $debe_secretaria > 0 && $dinero_disponible > 0 ) {
                $cobrado = min( $dinero_disponible, $debe_secretaria );
                $dinero_disponible -= $cobrado;
                $log_distribucion[] = "Secretaría: -$$cobrado";
                $wpdb->insert( $table_recaudos, ['transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 'concepto' => 'cuota_secretaria', 'monto' => $cobrado, 'fecha_recaudo' => current_time('mysql')] );
            }

            $ahorro_cobrado = 0;
            if ( $debe_ahorro > 0 && $dinero_disponible > 0 ) {
                $cobrado = min( $dinero_disponible, $debe_ahorro );
                $dinero_disponible -= $cobrado;
                $ahorro_cobrado += $cobrado;
                $log_distribucion[] = "Ahorro: +$$cobrado";
                $wpdb->insert( $table_recaudos, ['transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 'concepto' => 'ahorro', 'monto' => $cobrado, 'fecha_recaudo' => current_time('mysql')] );
            }

            if ( $dinero_disponible > 0 ) {
                $credito_activo = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}fondo_creditos WHERE user_id = {$tx->user_id} AND estado = 'activo' LIMIT 1");

                if ($credito_activo) {
                    $log_distribucion[] = "Abono Capital: -$$dinero_disponible";
                    $wpdb->insert( $table_recaudos, ['transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 'concepto' => 'capital_credito', 'monto' => $dinero_disponible, 'fecha_recaudo' => current_time('mysql')] );
                    $wpdb->query( $wpdb->prepare("UPDATE {$wpdb->prefix}fondo_creditos SET saldo_actual = saldo_actual - %f WHERE id = %d", $dinero_disponible, $credito_activo->id) );
                } else {
                    $log_distribucion[] = "Ahorro Extra: +$$dinero_disponible";
                    $ahorro_cobrado += $dinero_disponible;
                    $wpdb->insert( $table_recaudos, ['transaccion_id' => $tx->id, 'user_id' => $tx->user_id, 'concepto' => 'ahorro', 'monto' => $dinero_disponible, 'fecha_recaudo' => current_time('mysql')] );
                }
            }

            if ( $ahorro_cobrado > 0 ) {
                $nueva_fecha = ($ahorro_cobrado >= $debe_ahorro) ? $fecha_reporte->format('Y-m-d') : $cuenta->fecha_ultimo_aporte;
                $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}fondo_cuentas SET saldo_ahorro_capital = saldo_ahorro_capital + %f, fecha_ultimo_aporte = %s WHERE user_id = %d", $ahorro_cobrado, $nueva_fecha, $tx->user_id));
            }

            $detalle_final = $tx->detalle . " || PROCESADO: " . implode(', ', $log_distribucion);
            $wpdb->update( $wpdb->prefix . 'fondo_transacciones', ['estado' => 'aprobado', 'aprobado_por' => get_current_user_id(), 'fecha_aprobacion' => current_time('mysql'), 'detalle' => $detalle_final], ['id' => $tx->id] );

            $wpdb->query('COMMIT');
            wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&msg=approved' ) );
            exit;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_die('Error: ' . $e->getMessage());
        }
    }

    public function procesar_rechazo() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Acceso denegado: No tienes permisos de Tesorero.');
        $tx_id = intval( $_POST['tx_id'] );
        if ( ! wp_verify_nonce( $_POST['security'], 'rechazar_' . $tx_id ) ) wp_die('Seguridad fallida');
        global $wpdb;
        $motivo = sanitize_text_field( $_POST['motivo'] );
        $detalle = $wpdb->get_var("SELECT detalle FROM {$wpdb->prefix}fondo_transacciones WHERE id = $tx_id");
        $wpdb->update( $wpdb->prefix . 'fondo_transacciones', ['estado' => 'rechazado', 'aprobado_por' => get_current_user_id(), 'fecha_aprobacion' => current_time('mysql'), 'detalle' => $detalle . " || RECHAZADO: $motivo"], ['id' => $tx_id] );
        wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&msg=rejected' ) );
        exit;
    }

    public function procesar_desembolso() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Acceso denegado: No tienes permisos de Tesorero.');
        check_admin_referer('lud_approve_credit', 'security');
        global $wpdb;
        $id = intval($_POST['credito_id']);
        $entrega = sanitize_text_field($_POST['datos_entrega']);
        $credito = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE id=$id");
        if (!$credito) wp_die("No encontrado");

        $wpdb->update( $wpdb->prefix.'fondo_creditos', ['estado' => 'activo', 'monto_aprobado' => $credito->monto_solicitado, 'saldo_actual' => $credito->monto_solicitado, 'datos_entrega' => $entrega, 'fecha_aprobacion' => current_time('mysql')], ['id' => $id] );

        $this->generar_tabla_amortizacion($id);

        require_once LUD_PLUGIN_DIR . 'includes/class-module-creditos.php';
        $credito_actualizado = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE id=$id");
        $pdf_name = LUD_Module_Creditos::generar_pdf_final_static($credito_actualizado);

        $user = get_userdata($credito->user_id);
        $msg = "Tu crédito ha sido DESEMBOLSADO.<br><b>Instrucciones:</b> $entrega<br>Descarga tu contrato.";
        $attach = $pdf_name ? [WP_CONTENT_DIR . "/uploads/fondo_seguro/contratos/$pdf_name"] : [];
        wp_mail($user->user_email, "Crédito Desembolsado #$id", $msg, ['Content-Type: text/html'], $attach);

        wp_redirect(admin_url('admin.php?page=lud-tesoreria&msg=desembolsado'));
        exit;
    }

    private function generar_tabla_amortizacion($credito_id) {
        global $wpdb;
        $tabla_amort = $wpdb->prefix . 'fondo_amortizacion';
        $credito = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE id = $credito_id");
        $monto = floatval($credito->monto_solicitado);
        $plazo = intval($credito->plazo_meses);
        $tasa = floatval($credito->tasa_interes);
        
        $capital_mensual_base = round($monto / $plazo, 2);
        $suma_capitales = $capital_mensual_base * $plazo;
        $diferencia = $monto - $suma_capitales; 
        $interes_mensual = round($monto * ($tasa / 100), 2);

        $fecha_base = new DateTime( current_time('mysql') );
        $fecha_base->modify('+2 months'); 
        $fecha_base->setDate($fecha_base->format('Y'), $fecha_base->format('m'), 5);

        for ($i = 1; $i <= $plazo; $i++) {
            $fecha_vencimiento = clone $fecha_base;
            if ($i > 1) {
                $fecha_vencimiento->modify("+" . ($i - 1) . " months");
                $fecha_vencimiento->setDate($fecha_vencimiento->format('Y'), $fecha_vencimiento->format('m'), 5);
            }
            $capital_cuota = $capital_mensual_base;
            if ( $i == $plazo ) $capital_cuota += $diferencia;
            $valor_cuota_total = $capital_cuota + $interes_mensual;

            $wpdb->insert( $tabla_amort, [
                'credito_id' => $credito_id, 'numero_cuota' => $i,
                'fecha_vencimiento' => $fecha_vencimiento->format('Y-m-d'),
                'capital_programado' => $capital_cuota,
                'interes_programado' => $interes_mensual,
                'valor_cuota_total' => $valor_cuota_total,
                'estado' => 'pendiente'
            ]);
        }
    }

    // --- FUNCIONES NUEVAS: CIERRE MENSUAL Y ANUAL ---

    public function ejecutar_cierre_mensual_manual() {
        if (!current_user_can('manage_options')) wp_die('Sin permisos');
        $this->calcular_utilidad_mes_actual();
        wp_redirect(admin_url('admin.php?page=lud-tesoreria&msg=cierre_ok'));
        exit;
    }

    public function calcular_utilidad_mes_actual() {
        global $wpdb;
        $mes_actual = date('m');
        $anio_actual = date('Y');
        $ya_calculado = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fondo_utilidades_mensuales WHERE mes = $mes_actual AND anio = $anio_actual");
        if ( $ya_calculado > 0 ) return; 

        $ingresos = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_recaudos_detalle WHERE concepto IN ('interes_credito', 'multa') AND MONTH(fecha_recaudo) = $mes_actual AND YEAR(fecha_recaudo) = $anio_actual");
        $gastos = $wpdb->get_var("SELECT SUM(monto) FROM {$wpdb->prefix}fondo_gastos WHERE MONTH(fecha_gasto) = $mes_actual AND YEAR(fecha_gasto) = $anio_actual");
        $utilidad_neta = floatval($ingresos) - floatval($gastos);
        if ( $utilidad_neta <= 0 ) return; 

        $fecha_corte_mes = date('Y-m-01'); 
        $socios_todos = $wpdb->get_results("SELECT user_id, numero_acciones, fecha_ultimo_aporte FROM {$wpdb->prefix}fondo_cuentas WHERE estado_socio = 'activo'");
        
        $acciones_participantes = 0;
        $socios_habiles = [];

        foreach ($socios_todos as $s) {
            if ( $s->fecha_ultimo_aporte >= $fecha_corte_mes ) {
                $acciones_participantes += intval($s->numero_acciones);
                $socios_habiles[] = $s;
            } else {
                $wpdb->insert("{$wpdb->prefix}fondo_utilidades_mensuales", ['user_id' => $s->user_id, 'mes' => $mes_actual, 'anio' => $anio_actual, 'acciones_mes' => $s->numero_acciones, 'utilidad_asignada' => 0, 'estado' => 'provisional']);
            }
        }
        
        if ( $acciones_participantes == 0 ) return; 
        $valor_por_accion = $utilidad_neta / $acciones_participantes;
        
        foreach ( $socios_habiles as $s ) {
            $ganancia = $s->numero_acciones * $valor_por_accion;
            $wpdb->insert("{$wpdb->prefix}fondo_utilidades_mensuales", ['user_id' => $s->user_id, 'mes' => $mes_actual, 'anio' => $anio_actual, 'acciones_mes' => $s->numero_acciones, 'utilidad_asignada' => $ganancia, 'estado' => 'provisional']);
        }
    }

    public function procesar_liquidacion_anual() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) wp_die('Acceso denegado: No tienes permisos de Tesorero.');
        global $wpdb;
        $morosos = 0;
        $cuentas = $wpdb->get_results("SELECT user_id, fecha_ultimo_aporte FROM {$wpdb->prefix}fondo_cuentas WHERE estado_socio='activo'");
        $mes_corte = date('Y-m-01'); 
        foreach($cuentas as $c) { if ( $c->fecha_ultimo_aporte < $mes_corte ) $morosos++; }
        
        if ( $morosos > 0 ) wp_die("<div class='error'><h1>⛔ BLOQUEO</h1><p>Hay $morosos socios en mora.</p></div>");

        $anio = date('Y');
        $utilidades = $wpdb->get_results("SELECT user_id, SUM(utilidad_asignada) as total FROM {$wpdb->prefix}fondo_utilidades_mensuales WHERE anio = $anio AND estado = 'provisional' GROUP BY user_id");
        
        foreach ( $utilidades as $u ) {
            $wpdb->query("UPDATE {$wpdb->prefix}fondo_cuentas SET saldo_rendimientos = saldo_rendimientos + {$u->total} WHERE user_id = {$u->user_id}");
            $wpdb->update("{$wpdb->prefix}fondo_utilidades_mensuales", ['estado' => 'liquidado'], ['user_id' => $u->user_id, 'anio' => $anio]);
        }
        wp_redirect( admin_url('admin.php?page=lud-tesoreria&msg=liquidacion_exito') );
        exit;
    }

} // FIN DE LA CLASE