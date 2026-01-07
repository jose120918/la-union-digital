<?php
/**
 * Módulo de importaciones masivas.
 *
 * Permite subir archivos CSV/XLSX para cargar socios, aportes históricos y
 * créditos vigentes al sistema sin depender de registros manuales.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Module_Importaciones {

    /**
     * Registra los endpoints de importación.
     */
    public function __construct() {
        add_action( 'admin_post_lud_importar_socios', array( $this, 'procesar_importacion_socios' ) );
        add_action( 'admin_post_lud_importar_pagos_detallados', array( $this, 'procesar_importacion_pagos_detallados' ) );
        add_action( 'admin_post_lud_importar_creditos_csv', array( $this, 'procesar_importacion_creditos_csv' ) );
        add_action( 'admin_post_lud_importar_credito_xlsx', array( $this, 'procesar_importacion_credito' ) );

        add_action( 'lud_tarea_creditos_programados', array( $this, 'procesar_creditos_programados' ) );
        $this->programar_creditos_programados();
    }

    /**
     * Renderiza la vista de importaciones en tesorería.
     */
    public static function render_vista_importaciones() {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) {
            echo '<p class="lud-alert error">No tienes permisos para importar información.</p>';
            return;
        }

        $resultado = self::obtener_resultado_transitorio();

        if ( $resultado ) {
            echo '<div class="notice notice-success"><p><strong>Importación completada:</strong> ' . esc_html( $resultado['resumen'] ) . '</p>';
            if ( ! empty( $resultado['errores'] ) ) {
                echo '<ul style="margin-left:18px;">';
                foreach ( $resultado['errores'] as $error ) {
                    echo '<li>' . esc_html( $error ) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        ?>
        <div class="lud-card">
            <h2>📥 Importaciones masivas</h2>
            <p>Usa este módulo para cargar socios, aportes y créditos históricos. Cada importación genera movimientos aprobados y mantiene trazabilidad con detalle “Importación”.</p>
        </div>

        <div class="lud-card">
            <h3>1) Importar socios actuales</h3>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="lud_importar_socios">
                <?php wp_nonce_field( 'lud_importar_socios', 'lud_importar_seguridad' ); ?>
                <input type="file" name="archivo_csv" accept=".csv" required>
                <p class="description">Archivo esperado: “Datos usuarios.CSV” con separador por comas.</p>
                <button type="submit" class="button button-primary">Importar socios</button>
            </form>
        </div>

        <div class="lud-card">
            <h3>2) Importar pagos históricos (CSV detallado)</h3>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="lud_importar_pagos_detallados">
                <?php wp_nonce_field( 'lud_importar_pagos_detallados', 'lud_importar_seguridad' ); ?>
                <input type="file" name="archivo_csv" accept=".csv" required>
                <p class="description">Archivo esperado: “pagos_historicos.csv” con columnas por concepto (0 válido).</p>
                <button type="submit" class="button button-primary">Importar pagos</button>
            </form>
        </div>

        <div class="lud-card">
            <h3>3) Importar créditos (CSV)</h3>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="lud_importar_creditos_csv">
                <?php wp_nonce_field( 'lud_importar_creditos_csv', 'lud_importar_seguridad' ); ?>
                <input type="file" name="archivo_csv" accept=".csv" required>
                <p class="description">Archivo esperado: “creditos_historicos.csv”.</p>
                <button type="submit" class="button button-primary">Importar créditos</button>
            </form>
        </div>

        <div class="lud-card">
            <h3>4) Importar crédito vigente (XLSX)</h3>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="lud_importar_credito_xlsx">
                <?php wp_nonce_field( 'lud_importar_credito_xlsx', 'lud_importar_seguridad' ); ?>
                <p>
                    <label>Documento del socio (cédula)</label><br>
                    <input type="text" name="documento_socio">
                </p>
                <p>
                    <label>Nombre parcial del socio (opcional)</label><br>
                    <input type="text" name="nombre_socio" placeholder="Ej: Yohan Moreno">
                </p>
                <p>
                    <label>Tipo de crédito</label><br>
                    <select name="tipo_credito">
                        <option value="corriente">Crédito corriente</option>
                        <option value="agil">Crédito ágil</option>
                    </select>
                </p>
                <input type="file" name="archivo_xlsx" accept=".xlsx" required>
                <p class="description">Archivo esperado: “Yohan Moreno 14 julio 2025.xlsx”.</p>
                <button type="submit" class="button button-primary">Importar crédito</button>
            </form>
        </div>
        <?php
    }

    /**
     * Procesa la importación de socios.
     */
    public function procesar_importacion_socios() {
        $this->validar_permiso( 'lud_importar_socios' );
        $archivo = $this->obtener_archivo_subido( 'archivo_csv', array( 'csv' ) );

        $filas = $this->leer_csv( $archivo['tmp_name'], ',' );
        if ( empty( $filas ) ) {
            $this->redirigir_resultado( 'No se encontraron filas en el archivo.', array() );
        }

        $encabezados = array_map( 'trim', $filas[0] );
        $procesadas = 0;
        $errores = array();

        for ( $i = 1; $i < count( $filas ); $i++ ) {
            $fila = $filas[$i];
            if ( count( array_filter( $fila ) ) === 0 ) {
                continue;
            }
            $datos = $this->combinar_fila( $encabezados, $fila );

            $cedula = trim( $datos['Cédula'] ?? '' );
            $nombre = trim( $datos['Nombre'] ?? '' );
            $email = sanitize_email( $datos['E-mail'] ?? '' );

            if ( $cedula === '' || $nombre === '' ) {
                $errores[] = "Fila {$i}: falta cédula o nombre.";
                continue;
            }

            $user_id = $this->obtener_o_crear_usuario( $cedula, $email, $nombre, $errores, $i );
            if ( ! $user_id ) {
                continue;
            }

            $this->actualizar_ficha_socio( $user_id, $datos );
            $procesadas++;
        }

        $this->redirigir_resultado(
            "Socios importados: {$procesadas}.",
            $errores
        );
    }

    /**
     * Procesa la importación de pagos detallados.
     */
    public function procesar_importacion_pagos_detallados() {
        $this->validar_permiso( 'lud_importar_pagos_detallados' );
        $archivo = $this->obtener_archivo_subido( 'archivo_csv', array( 'csv' ) );
        $filas = $this->leer_csv_detectado( $archivo['tmp_name'] );

        $resultado = $this->importar_pagos_detallados( $filas );
        $this->redirigir_resultado( $resultado['resumen'], $resultado['errores'] );
    }

    /**
     * Procesa la importación de créditos desde CSV.
     */
    public function procesar_importacion_creditos_csv() {
        $this->validar_permiso( 'lud_importar_creditos_csv' );
        $archivo = $this->obtener_archivo_subido( 'archivo_csv', array( 'csv' ) );
        $filas = $this->leer_csv_detectado( $archivo['tmp_name'] );

        $resultado = $this->importar_creditos_csv( $filas );
        $this->redirigir_resultado( $resultado['resumen'], $resultado['errores'] );
    }

    /**
     * Procesa la importación de un crédito en XLSX.
     */
    public function procesar_importacion_credito() {
        $this->validar_permiso( 'lud_importar_credito_xlsx' );
        $archivo = $this->obtener_archivo_subido( 'archivo_xlsx', array( 'xlsx' ) );

        $documento = sanitize_text_field( $_POST['documento_socio'] ?? '' );
        $nombre = sanitize_text_field( $_POST['nombre_socio'] ?? '' );
        $tipo_credito = sanitize_text_field( $_POST['tipo_credito'] ?? 'corriente' );
        $tipo_credito = $tipo_credito === 'agil' ? 'agil' : 'corriente';

        if ( $documento === '' && $nombre === '' ) {
            $this->redirigir_resultado( 'Debes indicar la cédula o un nombre parcial del socio.', array() );
        }

        $user_id = $documento ? $this->buscar_usuario_por_documento( $documento ) : 0;
        if ( ! $user_id && $nombre ) {
            $user_id = $this->buscar_usuario_por_nombre( $nombre );
            if ( $user_id === -1 ) {
                $this->redirigir_resultado( "Se encontraron múltiples socios con el nombre parcial {$nombre}. Ajusta el filtro o usa la cédula.", array() );
            }
        }
        if ( ! $user_id ) {
            $mensaje = $documento ? "No se encontró el socio con cédula {$documento}." : "No se encontró el socio con el nombre parcial {$nombre}.";
            $this->redirigir_resultado( $mensaje, array() );
        }

        $filas = $this->leer_xlsx( $archivo['tmp_name'] );
        if ( empty( $filas ) ) {
            $this->redirigir_resultado( 'No se logró leer el archivo XLSX.', array() );
        }

        $resultado = $this->importar_credito_desde_filas( $filas, $user_id, $tipo_credito );
        $this->redirigir_resultado( $resultado['resumen'], $resultado['errores'] );
    }

    /**
     * Valida permisos y nonce.
     */
    private function validar_permiso( $accion ) {
        if ( ! current_user_can( 'lud_manage_tesoreria' ) ) {
            wp_die( 'No tienes permisos para importar.' );
        }
        if ( ! isset( $_POST['lud_importar_seguridad'] ) || ! wp_verify_nonce( $_POST['lud_importar_seguridad'], $accion ) ) {
            wp_die( 'Nonce inválido para importación.' );
        }
    }

    /**
     * Obtiene el archivo subido y valida extensión.
     */
    private function obtener_archivo_subido( $campo, $extensiones_validas ) {
        if ( empty( $_FILES[ $campo ]['name'] ) ) {
            wp_die( 'No se encontró el archivo a importar.' );
        }

        $archivo = $_FILES[ $campo ];
        $extension = strtolower( pathinfo( $archivo['name'], PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, $extensiones_validas, true ) ) {
            wp_die( 'Extensión inválida para importación.' );
        }

        if ( $archivo['error'] !== UPLOAD_ERR_OK ) {
            wp_die( 'Error al subir el archivo.' );
        }

        return $archivo;
    }

    /**
     * Lee un CSV según delimitador.
     */
    private function leer_csv( $ruta, $delimitador ) {
        $filas = array();
        if ( ( $handle = fopen( $ruta, 'r' ) ) !== false ) {
            while ( ( $data = fgetcsv( $handle, 0, $delimitador ) ) !== false ) {
                $filas[] = $data;
            }
            fclose( $handle );
        }
        return $filas;
    }

    /**
     * Lee un CSV detectando delimitador.
     */
    private function leer_csv_detectado( $ruta ) {
        $contenido = file_get_contents( $ruta );
        if ( $contenido === false ) {
            return array();
        }
        $lineas = preg_split( "/\\r\\n|\\r|\\n/", $contenido );
        $primera = $lineas[0] ?? '';
        $coma = substr_count( $primera, ',' );
        $punto_coma = substr_count( $primera, ';' );
        $delimitador = $punto_coma > $coma ? ';' : ',';

        $filas = array();
        if ( ( $handle = fopen( $ruta, 'r' ) ) !== false ) {
            while ( ( $data = fgetcsv( $handle, 0, $delimitador ) ) !== false ) {
                $filas[] = $data;
            }
            fclose( $handle );
        }
        return $filas;
    }

    /**
     * Combina encabezados con la fila.
     */
    private function combinar_fila( $encabezados, $fila ) {
        $resultado = array();
        $total = count( $encabezados );
        for ( $i = 0; $i < $total; $i++ ) {
            $resultado[ $encabezados[ $i ] ] = $fila[ $i ] ?? '';
        }
        return $resultado;
    }

    /**
     * Busca o crea un usuario por cédula.
     */
    private function obtener_o_crear_usuario( $cedula, $email, $nombre, &$errores, $fila ) {
        $user = get_user_by( 'login', $cedula );
        if ( ! $user && $email ) {
            $user = get_user_by( 'email', $email );
        }

        if ( ! $user ) {
            if ( empty( $email ) ) {
                $email = sanitize_email( $cedula . '@sin-correo.local' );
                $errores[] = "Fila {$fila}: correo vacío, se asignó {$email}.";
            }
            $password = wp_generate_password( 20, true, true );
            $user_id = wp_create_user( $cedula, $password, $email );
            if ( is_wp_error( $user_id ) ) {
                $errores[] = "Fila {$fila}: error al crear usuario ({$cedula}).";
                return 0;
            }
            wp_update_user( array(
                'ID' => $user_id,
                'display_name' => $nombre,
                'role' => 'lud_socio',
            ) );
            return $user_id;
        }

        if ( ! in_array( 'lud_socio', (array) $user->roles, true ) ) {
            $user->add_role( 'lud_socio' );
        }

        return $user->ID;
    }

    /**
     * Actualiza la ficha del socio con los datos del CSV.
     */
    private function actualizar_ficha_socio( $user_id, $datos ) {
        global $wpdb;

        $fecha_nacimiento = $this->normalizar_fecha( $datos['Fecha de Nacimiento'] ?? '' );
        $fecha_ingreso = $this->normalizar_fecha( $datos['Fecha de Ingreso al Fondo'] ?? '' );

        $cuenta = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );

        $data = array(
            'user_id' => $user_id,
            'numero_acciones' => $this->normalizar_entero( $datos['Número de acciones actuales'] ?? 0 ),
            'saldo_ahorro_capital' => $this->normalizar_monto( $datos['Ahorros a la Fecha (Diciembre 2025)'] ?? 0 ),
            'estado_socio' => 'activo',
            'tipo_documento' => 'CC',
            'numero_documento' => sanitize_text_field( $datos['Cédula'] ?? '' ),
            'fecha_nacimiento' => $fecha_nacimiento,
            'direccion_residencia' => sanitize_text_field( $datos['Dirección'] ?? '' ),
            'ciudad_pais' => $this->componer_ciudad_pais( $datos['Ciudad'] ?? '', $datos['País'] ?? '' ),
            'telefono_contacto' => sanitize_text_field( $datos['Teléfono'] ?? '' ),
            'email_contacto' => sanitize_email( $datos['E-mail'] ?? '' ),
            'fecha_ingreso_fondo' => $fecha_ingreso,
            'aporte_inicial' => $this->normalizar_monto( $datos['Monto de Aporte Inicial'] ?? 0 ),
            'actividad_economica' => sanitize_text_field( $datos['Actividad Económica'] ?? '' ),
            'origen_fondos' => sanitize_text_field( $datos['Fuente General de Ingresos'] ?? '' ),
            'banco_medio_pago' => sanitize_text_field( $datos['Banco o medio de pago utilizado para realizar los aportes'] ?? '' ),
            'beneficiario_nombre' => sanitize_text_field( $datos['Beneficiario 1'] ?? '' ),
            'beneficiario_parentesco' => sanitize_text_field( $datos['Parentesco 1'] ?? '' ),
        );

        if ( $cuenta ) {
            $wpdb->update( "{$wpdb->prefix}fondo_cuentas", $data, array( 'user_id' => $user_id ) );
        } else {
            $wpdb->insert( "{$wpdb->prefix}fondo_cuentas", $data );
        }

        $beneficiarios = array();
        for ( $i = 1; $i <= 3; $i++ ) {
            $nombre = trim( $datos[ "Beneficiario {$i}" ] ?? '' );
            if ( $nombre === '' ) {
                continue;
            }
            $beneficiarios[] = array(
                'nombre' => $nombre,
                'parentesco' => trim( $datos[ "Parentesco {$i}" ] ?? '' ),
                'porcentaje' => trim( $datos[ "Porcentaje {$i}" ] ?? '' ),
            );
        }

        update_user_meta( $user_id, 'lud_beneficiarios_detalle', wp_json_encode( $beneficiarios ) );
        update_user_meta( $user_id, 'lud_aporte_actual', $this->normalizar_monto( $datos['Monto de Aporte Actual-A la Fecha (Diciembre 2025)'] ?? 0 ) );
    }

    /**
     * Importa un CSV con pagos detallados por transacción.
     */
    private function importar_pagos_detallados( $filas ) {
        $errores = array();
        if ( empty( $filas ) ) {
            return array( 'resumen' => 'Archivo vacío.', 'errores' => $errores );
        }

        $encabezados = array_map( array( $this, 'normalizar_encabezado' ), $filas[0] );
        $mapa = array_flip( $encabezados );

        $requeridas = array( 'documento', 'fecha_pago' );
        foreach ( $requeridas as $columna ) {
            if ( ! isset( $mapa[ $columna ] ) ) {
                $errores[] = "Falta la columna obligatoria: {$columna}.";
            }
        }
        if ( ! empty( $errores ) ) {
            return array( 'resumen' => 'No se pudo procesar el archivo.', 'errores' => $errores );
        }

        $conceptos_validos = array(
            'ahorro' => 'ahorro',
            'cuota_secretaria' => 'cuota_secretaria',
            'capital_credito' => 'capital_credito',
            'interes_credito' => 'interes_credito',
            'interes_mora_credito' => 'interes_mora_credito',
            'multa' => 'multa',
            'excedente' => 'excedente',
        );

        $procesadas = 0;
        $transacciones = 0;

        for ( $i = 1; $i < count( $filas ); $i++ ) {
            $fila = $filas[ $i ];
            if ( count( array_filter( $fila ) ) === 0 ) {
                continue;
            }

            $documento = trim( $fila[ $mapa['documento'] ] ?? '' );
            $fecha = $this->normalizar_fecha( $fila[ $mapa['fecha_pago'] ] ?? '' );
            if ( $documento === '' || $fecha === '' ) {
                $errores[] = "Fila {$i}: documento o fecha inválidos.";
                continue;
            }

            $user_id = $this->buscar_usuario_por_documento( $documento );
            if ( ! $user_id ) {
                $errores[] = "Fila {$i}: no existe socio con cédula {$documento}.";
                continue;
            }

            $detalle = isset( $mapa['detalle'] ) ? sanitize_text_field( $fila[ $mapa['detalle'] ] ?? '' ) : '';
            $conceptos = array();
            $total = 0;

            foreach ( $conceptos_validos as $columna => $concepto ) {
                if ( ! isset( $mapa[ $columna ] ) ) {
                    continue;
                }
                $valor = $this->normalizar_monto( $fila[ $mapa[ $columna ] ] ?? 0 );
                if ( $valor > 0 ) {
                    $conceptos[ $concepto ] = $valor;
                    $total += $valor;
                }
            }

            if ( $total <= 0 ) {
                $errores[] = "Fila {$i}: pago sin valores.";
                continue;
            }

            $this->registrar_movimiento_importado( $user_id, $total, $fecha, $conceptos, $detalle );
            $transacciones++;
            $procesadas++;
        }

        return array(
            'resumen' => "Pagos procesados: {$procesadas}. Movimientos creados: {$transacciones}.",
            'errores' => $errores,
        );
    }

    /**
     * Importa créditos desde CSV (vigentes y cerrados).
     */
    private function importar_creditos_csv( $filas ) {
        $errores = array();
        if ( empty( $filas ) ) {
            return array( 'resumen' => 'Archivo vacío.', 'errores' => $errores );
        }

        $encabezados = array_map( array( $this, 'normalizar_encabezado' ), $filas[0] );
        $mapa = array_flip( $encabezados );

        $requeridas = array( 'documento', 'tipo_credito', 'monto_aprobado', 'fecha_inicio', 'fecha_fin' );
        foreach ( $requeridas as $columna ) {
            if ( ! isset( $mapa[ $columna ] ) ) {
                $errores[] = "Falta la columna obligatoria: {$columna}.";
            }
        }
        if ( ! empty( $errores ) ) {
            return array( 'resumen' => 'No se pudo procesar el archivo.', 'errores' => $errores );
        }

        $procesadas = 0;
        $creditos_creados = 0;

        for ( $i = 1; $i < count( $filas ); $i++ ) {
            $fila = $filas[ $i ];
            if ( count( array_filter( $fila ) ) === 0 ) {
                continue;
            }

            $documento = trim( $fila[ $mapa['documento'] ] ?? '' );
            $tipo = sanitize_text_field( $fila[ $mapa['tipo_credito'] ] ?? 'corriente' );
            $tipo = $tipo === 'agil' ? 'agil' : 'corriente';
            $monto = $this->normalizar_monto( $fila[ $mapa['monto_aprobado'] ] ?? 0 );
            $fecha_inicio = $this->normalizar_fecha( $fila[ $mapa['fecha_inicio'] ] ?? '' );
            $fecha_fin = $this->normalizar_fecha( $fila[ $mapa['fecha_fin'] ] ?? '' );

            if ( $documento === '' || $monto <= 0 || $fecha_inicio === '' ) {
                $errores[] = "Fila {$i}: documento, monto o fecha inválidos.";
                continue;
            }

            $user_id = $this->buscar_usuario_por_documento( $documento );
            if ( ! $user_id ) {
                $errores[] = "Fila {$i}: no existe socio con cédula {$documento}.";
                continue;
            }

            $tasa = isset( $mapa['tasa_interes'] ) ? $this->normalizar_monto( $fila[ $mapa['tasa_interes'] ] ?? 0 ) : 0;
            if ( $tasa <= 0 ) {
                $tasa = $tipo === 'agil' ? 1.5 : 2.0;
            }

            $saldo = isset( $mapa['saldo_actual'] ) ? $this->normalizar_monto( $fila[ $mapa['saldo_actual'] ] ?? 0 ) : 0;
            $monto_pagado = isset( $mapa['monto_pagado'] ) ? $this->normalizar_monto( $fila[ $mapa['monto_pagado'] ] ?? 0 ) : 0;
            $estado = isset( $mapa['estado_credito'] ) ? sanitize_text_field( $fila[ $mapa['estado_credito'] ] ?? '' ) : '';
            if ( $estado === '' ) {
                if ( $fecha_inicio && $fecha_inicio > date( 'Y-m-d' ) ) {
                    $estado = 'programado';
                } else {
                    $estado = $saldo > 0 ? 'activo' : ( $fecha_fin && $fecha_fin < date( 'Y-m-d' ) ? 'pagado' : 'activo' );
                }
            }
            if ( ! in_array( $estado, array( 'programado', 'activo', 'pagado', 'mora', 'rechazado', 'pendiente_tesoreria', 'fila_liquidez' ), true ) ) {
                $estado = 'programado';
            }
            if ( $monto_pagado < 0 ) {
                $monto_pagado = 0;
            }
            if ( $monto_pagado > 0 && $saldo <= 0 ) {
                $saldo = max( 0, $monto - $monto_pagado );
            }
            if ( $saldo <= 0 && $monto_pagado <= 0 ) {
                $saldo = $estado === 'pagado' ? 0 : $monto;
            }

            $plazo = $this->calcular_plazo_meses( $fecha_inicio, $fecha_fin, $tipo );
            if ( $plazo <= 0 ) {
                $errores[] = "Fila {$i}: no se pudo calcular el plazo.";
                continue;
            }

            $cuota_inicial = $this->calcular_cuota_inicial_aleman( $monto, $plazo, $tasa );

            global $wpdb;
            $wpdb->insert( "{$wpdb->prefix}fondo_creditos", array(
                'user_id' => $user_id,
                'tipo_credito' => $tipo,
                'monto_solicitado' => $monto,
                'monto_aprobado' => $monto,
                'codigo_seguimiento' => wp_generate_password( 8, false, false ),
                'plazo_meses' => $plazo,
                'tasa_interes' => $tasa,
                'cuota_estimada' => $cuota_inicial,
                'saldo_actual' => $saldo,
                'estado' => $estado,
                'fecha_solicitud' => $fecha_inicio . ' 00:00:00',
                'fecha_aprobacion' => $fecha_inicio . ' 00:00:00',
                'datos_entrega' => 'Importación de crédito histórico desde CSV.',
            ) );

            $credito_id = $wpdb->insert_id;
            $this->registrar_pago_credito_acumulado( $credito_id, $monto_pagado );
            $this->generar_amortizacion_aleman( $credito_id, $monto, $tasa, $plazo, $fecha_inicio, $tipo, $monto_pagado );

            $creditos_creados++;
            $procesadas++;
        }

        return array(
            'resumen' => "Créditos procesados: {$procesadas}. Créditos creados: {$creditos_creados}.",
            'errores' => $errores,
        );
    }

    /**
     * Inserta transacción y desglose por conceptos.
     */
    private function registrar_movimiento_importado( $user_id, $monto, $fecha, $conceptos, $detalle_extra = '' ) {
        global $wpdb;

        $detalle = 'Importación histórica';
        if ( $detalle_extra ) {
            $detalle .= ' | ' . $detalle_extra;
        }

        $wpdb->insert( "{$wpdb->prefix}fondo_transacciones", array(
            'user_id' => $user_id,
            'tipo' => 'pago_consolidado',
            'monto' => $monto,
            'metodo_pago' => 'importacion',
            'estado' => 'aprobado',
            'aprobado_por' => get_current_user_id(),
            'fecha_registro' => $fecha . ' 10:00:00',
            'fecha_aprobacion' => $fecha . ' 10:00:00',
            'detalle' => $detalle,
        ) );

        $transaccion_id = $wpdb->insert_id;

        foreach ( $conceptos as $concepto => $valor ) {
            $wpdb->insert( "{$wpdb->prefix}fondo_recaudos_detalle", array(
                'transaccion_id' => $transaccion_id,
                'user_id' => $user_id,
                'concepto' => $concepto,
                'monto' => $valor,
                'fecha_recaudo' => $fecha . ' 10:00:00',
            ) );
        }

        if ( isset( $conceptos['ahorro'] ) ) {
            $this->actualizar_fecha_ultimo_aporte( $user_id, $fecha );
        }
    }

    /**
     * Actualiza la fecha de último aporte si es mayor.
     */
    private function actualizar_fecha_ultimo_aporte( $user_id, $fecha ) {
        global $wpdb;
        $actual = $wpdb->get_var( $wpdb->prepare( "SELECT fecha_ultimo_aporte FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );
        if ( ! $actual || $fecha > $actual ) {
            $wpdb->update( "{$wpdb->prefix}fondo_cuentas", array( 'fecha_ultimo_aporte' => $fecha ), array( 'user_id' => $user_id ) );
        }
    }

    /**
     * Importa un crédito desde filas XLSX.
     */
    private function importar_credito_desde_filas( $filas, $user_id, $tipo_credito ) {
        $errores = array();
        $meta = $this->extraer_meta_credito( $filas );
        $tabla = $this->extraer_tabla_amortizacion( $filas );
        $credito_pagado = $this->extraer_credito_pagado( $filas );

        if ( empty( $meta['monto'] ) || empty( $meta['fecha_inicio'] ) ) {
            return array(
                'resumen' => 'No se encontraron datos mínimos del crédito.',
                'errores' => array( 'Verifica que el archivo tenga el bloque de meta datos.' ),
            );
        }

        if ( empty( $meta['cuotas'] ) && ! empty( $meta['fecha_inicio'] ) && ! empty( $meta['fecha_final'] ) ) {
            $meta['cuotas'] = $this->calcular_plazo_meses( $meta['fecha_inicio'], $meta['fecha_final'], $tipo_credito );
        }

        if ( empty( $meta['cuotas'] ) ) {
            return array(
                'resumen' => 'No se pudo determinar el número de cuotas del crédito.',
                'errores' => array( 'Completa el número de cuotas o las fechas de inicio y final.' ),
            );
        }

        $saldo_actual = $meta['monto'];
        if ( $credito_pagado > 0 ) {
            $saldo_actual = max( 0, $meta['monto'] - $credito_pagado );
        } elseif ( ! empty( $tabla['ultimo_saldo'] ) ) {
            $saldo_actual = max( 0, $tabla['ultimo_saldo'] );
        }

        $estado = $saldo_actual <= 0 ? 'pagado' : 'activo';

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}fondo_creditos", array(
            'user_id' => $user_id,
            'tipo_credito' => $tipo_credito,
            'monto_solicitado' => $meta['monto'],
            'monto_aprobado' => $meta['monto'],
            'codigo_seguimiento' => wp_generate_password( 8, false, false ),
            'plazo_meses' => $meta['cuotas'],
            'tasa_interes' => $meta['tasa'],
            'cuota_estimada' => $meta['cuota_fija'],
            'saldo_actual' => $saldo_actual,
            'estado' => $estado,
            'fecha_solicitud' => $meta['fecha_inicio'] ? $meta['fecha_inicio'] . ' 00:00:00' : current_time( 'mysql' ),
            'fecha_aprobacion' => $meta['fecha_inicio'] ? $meta['fecha_inicio'] . ' 00:00:00' : current_time( 'mysql' ),
            'datos_entrega' => 'Importación de crédito histórico desde XLSX.',
        ) );

        $credito_id = $wpdb->insert_id;
        $cuotas_insertadas = 0;

        if ( empty( $tabla['cuotas'] ) ) {
            $this->generar_amortizacion_aleman( $credito_id, $meta['monto'], $meta['tasa'], $meta['cuotas'], $meta['fecha_inicio'], $tipo_credito, $credito_pagado );
            $cuotas_insertadas = $meta['cuotas'];
        } else {
            foreach ( $tabla['cuotas'] as $cuota ) {
                $wpdb->insert( "{$wpdb->prefix}fondo_amortizacion", array(
                    'credito_id' => $credito_id,
                    'numero_cuota' => $cuota['numero'],
                    'fecha_vencimiento' => $cuota['fecha'],
                    'capital_programado' => $cuota['capital'],
                    'interes_programado' => $cuota['interes'],
                    'valor_cuota_total' => $cuota['total'],
                    'fecha_pago' => $cuota['pagado'] > 0 ? $cuota['fecha'] : null,
                    'monto_pagado' => $cuota['pagado'],
                    'estado' => $cuota['pagado'] > 0 ? 'pagado' : 'pendiente',
                ) );
                $cuotas_insertadas++;
            }
        }

        return array(
            'resumen' => "Crédito importado (ID {$credito_id}). Cuotas registradas: {$cuotas_insertadas}.",
            'errores' => $errores,
        );
    }

    /**
     * Extrae metadatos del crédito desde las filas.
     */
    private function extraer_meta_credito( $filas ) {
        $meta = array(
            'monto' => 0,
            'cuotas' => 0,
            'tasa' => 0,
            'cuota_fija' => 0,
            'fecha_inicio' => '',
            'fecha_final' => '',
        );

        $mapa = array(
            'monto' => 'monto',
            'numero de cuotas' => 'cuotas',
            'tasa de interes' => 'tasa',
            'valor cuota fija' => 'cuota_fija',
            'fecha de inicio' => 'fecha_inicio',
            'fecha final' => 'fecha_final',
        );

        foreach ( $filas as $fila ) {
            $total = count( $fila );
            for ( $i = 0; $i < $total; $i++ ) {
                $label = $this->normalizar_etiqueta( $fila[ $i ] ?? '' );
                if ( ! isset( $mapa[ $label ] ) ) {
                    continue;
                }
                $valor = $fila[ $i + 1 ] ?? '';
                switch ( $mapa[ $label ] ) {
                    case 'monto':
                        $meta['monto'] = $this->normalizar_monto( $valor );
                        break;
                    case 'cuotas':
                        $meta['cuotas'] = $this->normalizar_entero( $valor );
                        break;
                    case 'tasa':
                        $meta['tasa'] = $this->normalizar_monto( $valor );
                        break;
                    case 'cuota_fija':
                        $meta['cuota_fija'] = $this->normalizar_monto( $valor );
                        break;
                    case 'fecha_inicio':
                        $meta['fecha_inicio'] = $this->normalizar_fecha( $valor );
                        break;
                    case 'fecha_final':
                        $meta['fecha_final'] = $this->normalizar_fecha( $valor );
                        break;
                }
            }
        }

        return $meta;
    }

    /**
     * Extrae monto pagado parcial desde filas.
     */
    private function extraer_credito_pagado( $filas ) {
        for ( $i = 0; $i < count( $filas ); $i++ ) {
            $label = strtolower( trim( $filas[$i][0] ?? '' ) );
            if ( $label === 'crédito pagado' || $label === 'credito pagado' ) {
                return $this->normalizar_monto( $filas[$i + 1][0] ?? 0 );
            }
        }
        return 0;
    }

    /**
     * Extrae la tabla de amortización desde el XLSX.
     */
    private function extraer_tabla_amortizacion( $filas ) {
        $resultado = array(
            'cuotas' => array(),
            'ultimo_saldo' => 0,
        );

        $inicio = null;
        $indices = array();

        foreach ( $filas as $idx => $fila ) {
            if ( strtolower( trim( $fila[0] ?? '' ) ) === 'indice' ) {
                $inicio = $idx + 1;
                $indices = $this->mapear_indices_tabla( $fila );
                break;
            }
        }

        if ( $inicio === null ) {
            return $resultado;
        }

        for ( $i = $inicio; $i < count( $filas ); $i++ ) {
            $fila = $filas[$i];
            $indice_valor = $fila[ $indices['indice'] ] ?? '';
            if ( $indice_valor === '' ) {
                continue;
            }
            if ( strtolower( trim( $fila[0] ?? '' ) ) === 'total' ) {
                break;
            }
            if ( ! is_numeric( $indice_valor ) ) {
                continue;
            }

            $fecha_serial = $fila[ $indices['fecha'] ] ?? '';
            $fecha = $this->normalizar_fecha( $fecha_serial );
            $capital = $this->normalizar_monto( $fila[ $indices['capital'] ] ?? 0 );
            $interes = $this->normalizar_monto( $fila[ $indices['interes'] ] ?? 0 );
            $total = $this->normalizar_monto( $fila[ $indices['total'] ] ?? 0 );
            $pagado = $this->normalizar_monto( $fila[ $indices['pagado'] ] ?? 0 );
            $saldo_real = $this->normalizar_monto( $fila[ $indices['saldo'] ] ?? 0 );

            if ( $saldo_real > 0 ) {
                $resultado['ultimo_saldo'] = $saldo_real;
            }

            $resultado['cuotas'][] = array(
                'numero' => $this->normalizar_entero( $fila[ $indices['cuota'] ] ?? 0 ),
                'fecha' => $fecha ?: gmdate( 'Y-m-d', current_time( 'timestamp' ) ),
                'capital' => $capital,
                'interes' => $interes,
                'total' => $total,
                'pagado' => $pagado,
            );
        }

        return $resultado;
    }

    /**
     * Mapea los índices de columnas de la tabla.
     */
    private function mapear_indices_tabla( $fila ) {
        $mapa = array(
            'indice' => 0,
            'cuota' => 1,
            'fecha' => 2,
            'saldo' => 4,
            'capital' => 5,
            'interes' => 6,
            'total' => 8,
            'pagado' => 10,
        );

        return $mapa;
    }

    /**
     * Lee un XLSX usando ZipArchive.
     */
    private function leer_xlsx( $ruta ) {
        $zip = new ZipArchive();
        if ( $zip->open( $ruta ) !== true ) {
            return array();
        }

        $shared = array();
        if ( $zip->locateName( 'xl/sharedStrings.xml' ) !== false ) {
            $xml = simplexml_load_string( $zip->getFromName( 'xl/sharedStrings.xml' ) );
            if ( $xml ) {
                foreach ( $xml->si as $si ) {
                    $textos = $si->xpath( './/t' );
                    $valor = '';
                    if ( $textos ) {
                        foreach ( $textos as $texto ) {
                            $valor .= (string) $texto;
                        }
                    }
                    $shared[] = $valor;
                }
            }
        }

        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        if ( ! $sheet_xml ) {
            $zip->close();
            return array();
        }

        $sheet = simplexml_load_string( $sheet_xml );
        if ( ! $sheet ) {
            $zip->close();
            return array();
        }

        $filas = array();
        foreach ( $sheet->sheetData->row as $row ) {
            $fila = array();
            foreach ( $row->c as $cell ) {
                $ref = (string) $cell['r'];
                $columna = preg_replace( '/\d+/', '', $ref );
                $indice = $this->columna_a_indice( $columna );
                $valor = '';
                if ( isset( $cell->v ) ) {
                    $valor = (string) $cell->v;
                    if ( (string) $cell['t'] === 's' ) {
                        $valor = $shared[ intval( $valor ) ] ?? '';
                    }
                }
                $fila[ $indice ] = $valor;
            }
            ksort( $fila );
            $filas[] = array_values( $fila );
        }

        $zip->close();
        return $filas;
    }

    /**
     * Convierte letras de columna (A, B, AA) a índice.
     */
    private function columna_a_indice( $columna ) {
        $columna = strtoupper( $columna );
        $longitud = strlen( $columna );
        $indice = 0;
        for ( $i = 0; $i < $longitud; $i++ ) {
            $indice = $indice * 26 + ( ord( $columna[ $i ] ) - 64 );
        }
        return $indice - 1;
    }

    /**
     * Mapea los encabezados de meses en CSV simples.
     */
    private function mapear_columnas_meses( $encabezados ) {
        $mapa = array();
        foreach ( $encabezados as $indice => $nombre ) {
            $mes = $this->obtener_numero_mes( $nombre );
            if ( $mes ) {
                $mapa[ $mes ] = $indice;
            }
        }
        return $mapa;
    }

    /**
     * Mapea columnas con AHORRO + CUOTA + INTERÉS + MULTA.
     */
    private function mapear_columnas_detalladas( $encabezados ) {
        $mapeo = array();
        $mes_actual = null;
        $orden = array();

        foreach ( $encabezados as $indice => $nombre ) {
            $nombre = trim( $nombre );
            if ( $nombre === '' ) {
                continue;
            }
            $es_mes = $this->obtener_numero_mes( $nombre );
            $es_ahorro = stripos( $nombre, 'AHORRO' ) !== false;
            $es_columna_detalle = stripos( $nombre, 'CUOTA' ) !== false || stripos( $nombre, 'INTERES' ) !== false || stripos( $nombre, 'MULTA' ) !== false;

            if ( $es_ahorro || ( $es_mes && ! $es_columna_detalle && stripos( $nombre, 'ASOCIADOS' ) === false ) ) {
                $mes_actual = $es_mes;
                if ( $mes_actual ) {
                    $mapeo[ $mes_actual ] = array(
                        'ahorro' => $indice,
                        'cuota' => null,
                        'interes' => null,
                        'multa' => null,
                    );
                    $orden = array( 'cuota', 'interes', 'multa' );
                }
                continue;
            }

            if ( $mes_actual && ! empty( $orden ) ) {
                $tipo = array_shift( $orden );
                if ( $tipo ) {
                    $mapeo[ $mes_actual ][ $tipo ] = $indice;
                }
            }
        }

        foreach ( $mapeo as $mes => $columnas ) {
            foreach ( array( 'cuota', 'interes', 'multa' ) as $tipo ) {
                if ( $columnas[ $tipo ] === null ) {
                    $mapeo[ $mes ][ $tipo ] = -1;
                }
            }
        }

        return $mapeo;
    }

    /**
     * Obtiene el valor de una columna asegurando índice válido.
     */
    private function valor_columna_segura( $fila, $indice ) {
        if ( $indice === null || $indice < 0 ) {
            return '';
        }
        return $fila[ $indice ] ?? '';
    }

    /**
     * Obtiene el número de mes desde un encabezado.
     */
    private function obtener_numero_mes( $texto ) {
        $texto = strtoupper( $texto );
        $meses = array(
            'ENERO' => 1,
            'FEBRERO' => 2,
            'MARZO' => 3,
            'ABRIL' => 4,
            'MAYO' => 5,
            'JUNIO' => 6,
            'JULIO' => 7,
            'AGOSTO' => 8,
            'SEPTIEMBRE' => 9,
            'OCTUBRE' => 10,
            'NOVIEMBRE' => 11,
            'DICIEMBRE' => 12,
        );
        foreach ( $meses as $nombre => $numero ) {
            if ( strpos( $texto, $nombre ) !== false ) {
                return $numero;
            }
        }
        return 0;
    }

    /**
     * Normaliza etiquetas con o sin tildes.
     */
    private function normalizar_etiqueta( $texto ) {
        $texto = strtolower( trim( (string) $texto ) );
        $reemplazos = array(
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ñ' => 'n',
        );
        return strtr( $texto, $reemplazos );
    }

    /**
     * Normaliza encabezados de CSV.
     */
    private function normalizar_encabezado( $texto ) {
        $texto = $this->normalizar_etiqueta( $texto );
        $texto = str_replace( array( ' ', '-', '.', '/' ), '_', $texto );
        $texto = preg_replace( '/_+/', '_', $texto );
        return trim( $texto, '_' );
    }

    /**
     * Calcula el plazo en meses según fechas.
     */
    private function calcular_plazo_meses( $fecha_inicio, $fecha_fin, $tipo ) {
        if ( $tipo === 'agil' ) {
            return 1;
        }
        if ( ! $fecha_inicio || ! $fecha_fin ) {
            return 0;
        }
        try {
            $inicio = new DateTime( $fecha_inicio );
            $fin = new DateTime( $fecha_fin );
        } catch ( Exception $e ) {
            return 0;
        }
        $diff = $inicio->diff( $fin );
        $meses = ( $diff->y * 12 ) + $diff->m;
        if ( $diff->d >= 1 ) {
            $meses++;
        }
        return max( 1, $meses );
    }

    /**
     * Calcula la cuota inicial bajo amortización alemana.
     */
    private function calcular_cuota_inicial_aleman( $monto, $plazo, $tasa ) {
        if ( $plazo <= 0 ) {
            return 0;
        }
        $capital = $monto / $plazo;
        $interes = $monto * ( $tasa / 100 );
        return round( $capital + $interes, 2 );
    }

    /**
     * Calcula la fecha de la primera cuota según estatutos.
     */
    private function calcular_fecha_primera_cuota( $fecha_inicio, $tipo ) {
        try {
            $fecha = new DateTime( $fecha_inicio );
        } catch ( Exception $e ) {
            return '';
        }
        $meses = $tipo === 'agil' ? 1 : 2;
        $fecha->modify( "+{$meses} months" );
        $fecha->setDate( $fecha->format( 'Y' ), $fecha->format( 'm' ), 5 );
        return $fecha->format( 'Y-m-d' );
    }

    /**
     * Genera amortización alemana para créditos importados.
     */
    private function generar_amortizacion_aleman( $credito_id, $monto, $tasa, $plazo, $fecha_inicio, $tipo, $capital_pagado = 0 ) {
        global $wpdb;
        $tabla = $wpdb->prefix . 'fondo_amortizacion';
        $capital = $monto / $plazo;
        $saldo = $monto;
        $fecha_base = $this->calcular_fecha_primera_cuota( $fecha_inicio, $tipo );
        $capital_pagado = max( 0, $capital_pagado );
        $capital_pagado_acumulado = 0;

        for ( $i = 1; $i <= $plazo; $i++ ) {
            $interes = $saldo * ( $tasa / 100 );
            $cuota_total = $capital + $interes;
            $fecha_vencimiento = $fecha_base;
            if ( $i > 1 && $fecha_base ) {
                $fecha = new DateTime( $fecha_base );
                $fecha->modify( '+' . ( $i - 1 ) . ' months' );
                $fecha->setDate( $fecha->format( 'Y' ), $fecha->format( 'm' ), 5 );
                $fecha_vencimiento = $fecha->format( 'Y-m-d' );
            }

            $estado = 'pendiente';
            $fecha_pago = null;
            $monto_pagado = 0;
            $capital_pagado_acumulado += $capital;
            if ( $capital_pagado > 0 && $capital_pagado_acumulado <= $capital_pagado + 0.01 ) {
                $estado = 'pagado';
                $fecha_pago = $fecha_vencimiento;
                $monto_pagado = $cuota_total;
            }

            $wpdb->insert( $tabla, array(
                'credito_id' => $credito_id,
                'numero_cuota' => $i,
                'fecha_vencimiento' => $fecha_vencimiento,
                'capital_programado' => $capital,
                'interes_programado' => $interes,
                'valor_cuota_total' => $cuota_total,
                'fecha_pago' => $fecha_pago,
                'monto_pagado' => $monto_pagado,
                'estado' => $estado,
            ) );

            $saldo -= $capital;
        }
    }

    /**
     * Registra el total pagado acumulado de un crédito.
     */
    private function registrar_pago_credito_acumulado( $credito_id, $monto_pagado ) {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}fondo_creditos_pagos", array(
            'credito_id' => $credito_id,
            'monto_pagado' => $monto_pagado,
            'fecha_actualizacion' => current_time( 'mysql' ),
        ) );
    }

    /**
     * Programa la tarea para activar créditos con fecha futura.
     */
    private function programar_creditos_programados() {
        if ( ! wp_next_scheduled( 'lud_tarea_creditos_programados' ) ) {
            wp_schedule_event( time(), 'daily', 'lud_tarea_creditos_programados' );
        }
    }

    /**
     * Activa créditos programados cuando llega la fecha de inicio.
     */
    public function procesar_creditos_programados() {
        global $wpdb;
        $hoy = date( 'Y-m-d' );
        $tabla = $wpdb->prefix . 'fondo_creditos';

        $creditos = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM $tabla WHERE estado = %s AND DATE(fecha_solicitud) <= %s",
            'programado',
            $hoy
        ) );

        foreach ( $creditos as $credito ) {
            $wpdb->update(
                $tabla,
                array(
                    'estado' => 'activo',
                    'fecha_aprobacion' => current_time( 'mysql' ),
                ),
                array( 'id' => $credito->id )
            );
        }
    }

    /**
     * Normaliza montos con separadores locales.
     */
    private function normalizar_monto( $valor ) {
        $valor = trim( (string) $valor );
        if ( $valor === '' ) {
            return 0;
        }
        $valor = str_replace( array( '$', ' ', "\xc2\xa0", "'" ), '', $valor );
        if ( preg_match( '/^\d{1,3}(\.\d{3})+$/', $valor ) || preg_match( '/^\d+\.\d{3}$/', $valor ) ) {
            $valor = str_replace( '.', '', $valor );
        } elseif ( strpos( $valor, ',' ) !== false ) {
            $valor = str_replace( '.', '', $valor );
            $valor = str_replace( ',', '.', $valor );
        }
        $valor = preg_replace( '/[^0-9.-]/', '', $valor );
        return $valor === '' ? 0 : floatval( $valor );
    }

    /**
     * Normaliza enteros.
     */
    private function normalizar_entero( $valor ) {
        return intval( preg_replace( '/[^0-9]/', '', (string) $valor ) );
    }

    /**
     * Normaliza fechas (dd/mm/yyyy, yyyy-mm-dd o serial Excel).
     */
    private function normalizar_fecha( $valor ) {
        $valor = trim( (string) $valor );
        if ( $valor === '' ) {
            return '';
        }
        if ( is_numeric( $valor ) ) {
            return $this->fecha_desde_excel( floatval( $valor ) );
        }
        $valor = str_replace( '-', '/', $valor );
        $fecha = DateTime::createFromFormat( 'd/m/Y', $valor );
        if ( $fecha ) {
            return $fecha->format( 'Y-m-d' );
        }
        $fecha_iso = DateTime::createFromFormat( 'Y/m/d', $valor );
        if ( $fecha_iso ) {
            return $fecha_iso->format( 'Y-m-d' );
        }
        return '';
    }

    /**
     * Convierte fecha serial de Excel a Y-m-d.
     */
    private function fecha_desde_excel( $serial ) {
        if ( $serial <= 0 ) {
            return '';
        }
        $timestamp = ( $serial - 25569 ) * 86400;
        return gmdate( 'Y-m-d', $timestamp );
    }

    /**
     * Componer ciudad y país.
     */
    private function componer_ciudad_pais( $ciudad, $pais ) {
        $ciudad = trim( $ciudad );
        $pais = trim( $pais );
        if ( $ciudad && $pais ) {
            return $ciudad . ', ' . $pais;
        }
        return $ciudad ?: $pais;
    }

    /**
     * Busca usuario por documento en WordPress o en fondo_cuentas.
     */
    private function buscar_usuario_por_documento( $documento ) {
        $documento = trim( $documento );
        if ( $documento === '' ) {
            return 0;
        }

        $user = get_user_by( 'login', $documento );
        if ( $user ) {
            return $user->ID;
        }

        global $wpdb;
        $user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}fondo_cuentas WHERE numero_documento = %s",
            $documento
        ) );

        return intval( $user_id );
    }

    /**
     * Busca usuario por fragmento de nombre visible.
     */
    private function buscar_usuario_por_nombre( $nombre ) {
        $nombre = trim( $nombre );
        if ( $nombre === '' ) {
            return 0;
        }

        $consulta = new WP_User_Query( array(
            'search' => '*' . esc_attr( $nombre ) . '*',
            'search_columns' => array( 'display_name' ),
            'number' => 5,
        ) );

        $usuarios = $consulta->get_results();
        if ( count( $usuarios ) === 1 ) {
            return $usuarios[0]->ID;
        }
        if ( count( $usuarios ) > 1 ) {
            return -1;
        }

        return 0;
    }

    /**
     * Guarda resultados en transient.
     */
    private function redirigir_resultado( $resumen, $errores ) {
        $key = 'lud_importacion_' . get_current_user_id();
        set_transient( $key, array(
            'resumen' => $resumen,
            'errores' => $errores,
        ), 60 );

        wp_redirect( admin_url( 'admin.php?page=lud-tesoreria&view=importaciones&lud_importacion=1' ) );
        exit;
    }

    /**
     * Recupera el resultado de la importación.
     */
    private static function obtener_resultado_transitorio() {
        if ( empty( $_GET['lud_importacion'] ) ) {
            return null;
        }
        $key = 'lud_importacion_' . get_current_user_id();
        $resultado = get_transient( $key );
        delete_transient( $key );
        return $resultado;
    }
}
