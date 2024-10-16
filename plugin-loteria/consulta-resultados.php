<?php
/**
 * Plugin Name: Concursos Loteria
 * Description: Plugin para buscar e exibir resultados de concursos da Mega-Sena.
 * Version: 1.0
 * Author: Fabiano Maximiano
 */

if (!class_exists('Concursos_Loteria')) {

    class Concursos_Loteria {

        public function __construct() {
            // Registrar hooks necessários
            add_action('init', array($this, 'registrar_post_type_loterias'));
            add_action('init', array($this, 'registrar_shortcode_loterias'));

            // Hook para ativar o plugin
            register_activation_hook(__FILE__, array($this, 'ativar_plugin'));
        }

        /**
         * Função para ativar o plugin (chamada automaticamente)
         */
        public function ativar_plugin() {
            // Registrar o post type e flush para garantir a reescrita correta das URLs
            $this->registrar_post_type_loterias();
            flush_rewrite_rules();
        }

        /**
         * Registra o post type "Loterias" onde os resultados serão salvos
         */
        public function registrar_post_type_loterias() {
            $labels = array(
                'name' => 'Loterias',
                'singular_name' => 'Loteria',
                'add_new' => 'Adicionar Novo Resultado',
                'add_new_item' => 'Adicionar Novo Resultado',
                'edit_item' => 'Editar Resultado',
                'new_item' => 'Novo Resultado',
                'view_item' => 'Ver Resultado',
                'search_items' => 'Pesquisar Resultados',
                'not_found' => 'Nenhum Resultado Encontrado',
                'not_found_in_trash' => 'Nenhum Resultado no Lixo'
            );

            $args = array(
                'labels' => $labels,
                'public' => true,
                'has_archive' => true,
                'supports' => array('title', 'editor'),
                'menu_icon' => 'dashicons-tickets',
            );

            register_post_type('loterias', $args);
        }

        /**
         * Registra o shortcode [loterias]
         */
        public function registrar_shortcode_loterias() {
            add_shortcode('loterias', array($this, 'shortcode_loterias'));
        }

        /**
         * Função do shortcode [loterias concurso=""]
         * @param array $atts - Atributos do shortcode
         * @return string
         */
        public function shortcode_loterias($atts) {
            // Definir atributos padrão
            $atts = shortcode_atts(
                array(
                    'concurso' => 'ultimo',
                ), 
                $atts
            );

            // Sanitizar os atributos
            $concurso = sanitize_text_field($atts['concurso']);

            // Verificar se o concurso já está salvo
            if ($concurso !== 'ultimo') {
                // Procurar pelo concurso no post-type 'loterias'
                $resultado = $this->buscar_concurso_post_type($concurso);
                if ($resultado) {
                    return $this->exibir_concurso($resultado);
                }
            }

            // Caso o concurso seja "ultimo" ou não encontrado, consultar a API
            $resultado = $this->busca_concurso_api($concurso);

            // Verificar se houve erro
            if (is_wp_error($resultado)) {
                return 'Erro ao buscar o resultado: ' . esc_html($resultado->get_error_message());
            }

            // Verificar se o concurso já foi salvo
            $concurso_existente = $this->buscar_concurso_post_type($resultado['concurso']);
            if (!$concurso_existente) {
                // Salvar no post type
                $this->salvar_concurso_post_type($resultado);
            }

            // Exibir o resultado
            return $this->exibir_concurso($resultado);
        }

        /**
         * Função para buscar dados da API
         * @param string $concurso - Número do concurso ou "ultimo"
         * @return array|WP_Error - Dados do concurso ou erro
         */
        private function busca_concurso_api($concurso) {
            // URL da API da Mega-Sena
            $url = 'https://loteriascaixa-api.herokuapp.com/api/megasena/' . $concurso;

            // Fazer a requisição à API
            $response = wp_remote_get($url);

            // Verificar se a requisição falhou
            if (is_wp_error($response)) {
                return new WP_Error('api_error', 'Falha ao acessar a API.');
            }

            // Verificar o código de status da resposta
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code != 200) {
                return new WP_Error('api_error', 'Erro na API: Código ' . $status_code);
            }

            // Decodificar a resposta JSON
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            // Verificar erro na decodificação
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('json_error', 'Erro ao decodificar o JSON.');
            }

            return $data;
        }

        /**
         * Função para buscar concurso salvo no post type
         * @param string $concurso - Número do concurso
         * @return array|bool - Dados do concurso ou false se não encontrado
         */
        private function buscar_concurso_post_type($concurso) {
            $args = array(
                'post_type' => 'loterias',
                'meta_query' => array(
                    array(
                        'key' => 'concurso',
                        'value' => $concurso,
                    )
                ),
                'posts_per_page' => 1,
            );

            $query = new WP_Query($args);

            if ($query->have_posts()) {
                $query->the_post();
                $resultado = array(
                    'concurso' => get_post_meta(get_the_ID(), 'concurso', true),
                    'data' => get_post_meta(get_the_ID(), 'data', true),
                    'dezenas' => get_post_meta(get_the_ID(), 'dezenas', true),
                );
                wp_reset_postdata();
                return $resultado;
            }

            return false;
        }

        /**
         * Função para salvar o concurso no post type 'Loterias'
         * @param array $dados - Dados do concurso
         */
        private function salvar_concurso_post_type($dados) {
            $post_id = wp_insert_post(array(
                'post_title' => 'Concurso ' . $dados['concurso'],
                'post_type' => 'loterias',
                'post_status' => 'publish',
                'meta_input' => array(
                    'concurso' => $dados['concurso'],
                    'data' => $dados['data'],
                    'dezenas' => implode(', ', $dados['dezenas']),
                ),
            ));
        }

        /**
         * Função para exibir o concurso no front-end
         * @param array $dados - Dados do concurso
         * @return string - HTML formatado
         */
        private function exibir_concurso($dados) {
            if (empty($dados) || !isset($dados['concurso'], $dados['data'], $dados['dezenas'])) {
                return 'Dados incompletos para exibição.';
            }

            ob_start();
            ?>
            <div class="loteria-container">
                <h2>Concurso: <?php echo esc_html($dados['concurso']); ?></h2>
                <p>Data: <?php echo esc_html($dados['data']); ?></p>
                <div class="loteria-dezenas">
                    <p>Dezenas sorteadas:</p>
                    <ul>
                        <li><?php echo esc_html($dados['dezenas']); ?></li>
                    </ul>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
    }

    // Inicializar o plugin
    new Concursos_Loteria();
}
