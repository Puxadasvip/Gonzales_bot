<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    define('USUARIO_SERPRO', '45474232888'); 
    define('SENHA_SERPRO', 'Rickgov192&#@');
    define('TOKEN_FILE', 'token.txt');

    function obterNovoToken() {
        $data = [
            "imei" => "550249443172777",
            "latitude" => 31.24916,
            "longitude" => 121.48789833333333,
            "username" => USUARIO_SERPRO,
            "password" => SENHA_SERPRO
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://radar.serpro.gov.br/core-rest/gip-rest/auth/loginTalonario",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Accept-Encoding: gzip",
                "Connection: Keep-Alive",
                "User-Agent: Dalvik/2.1.0 (Linux; U; Android 9; ASUS_I005DA Build/PI)",
                "Content-Length: " . strlen(json_encode($data))
            ],
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($http_code == 200 && $response) {
            $responseData = json_decode($response, true);
            if (isset($responseData['token'])) {
                $cleanToken = str_replace('Token ', '', $responseData['token']);
                file_put_contents(TOKEN_FILE, $cleanToken);
                return $cleanToken;
            }
        }
        
        error_log("Falha ao obter token. HTTP Code: $http_code, Error: $error");
        return null; 
    }

    function realizarConsulta($endpoint, $token) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Token $token",
                "Accept-Encoding: gzip",
                "User-Agent: Dalvik/2.1.0 (Linux; U; Android 9; ASUS_I005DA Build/PI)",
                "Connection: Keep-Alive",
                "Host: radar.serpro.gov.br"
            ],
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch) ? 'Erro cURL: ' . curl_error($ch) : null;
        
        curl_close($ch);

        return ['body' => $res, 'status' => $status, 'error' => $error];
    }

    function formatarData($data) {
        if (empty($data) || $data === null) {
            return null;
        }
        
        try {
            $timestamp = strtotime($data);
            if ($timestamp === false || $timestamp < 0 || $timestamp > time() + (10 * 365 * 24 * 60 * 60)) {
                return null;
            }
            return date('Y-m-d H:i:s', $timestamp);
        } catch (Exception $e) {
            return null;
        }
    }

    $apikey = $_GET['apikey'] ?? '';
    if ($apikey !== 'gonzales') {
        http_response_code(401);
        echo json_encode(['erro' => 'API key invalida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = $_GET['string'] ?? '';
    $input = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($input)));

    if (empty($input)) {
        http_response_code(400);
        echo json_encode(['erro' => 'Parametro "string" e obrigatorio.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isPlaca = false;
    if (preg_match('/^\d{11}$/', $input)) {
        $endpoint = "https://radar.serpro.gov.br/consultas-departamento-transito/api/condutores/cpf/$input/";
    } elseif (preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $input)) {
        $endpoint = "https://radar.serpro.gov.br/consultas-departamento-transito/api/veiculo/placa/$input";
        $isPlaca = true;
    } else {
        http_response_code(400);
        echo json_encode(['erro' => 'Entrada invalida. Informe CPF (11 digitos) ou PLACA (formato ABC1234 ou ABC1D23).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = @file_get_contents(TOKEN_FILE);
    if (empty($token) || trim($token) === '') {
        $token = obterNovoToken();
        if (!$token) {
            http_response_code(500);
            echo json_encode(['erro' => 'Falha ao obter token. Verifique as credenciais.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $resultado = realizarConsulta($endpoint, trim($token));

    if ($resultado['status'] == 403 || $resultado['status'] == 401) {
        $novoToken = obterNovoToken();
        if ($novoToken) {
            $resultado = realizarConsulta($endpoint, $novoToken);
        } else {
            http_response_code(500);
            echo json_encode(['erro' => 'Token expirado. Falha ao renovar.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($resultado['error']) {
        http_response_code(500);
        echo json_encode(['erro' => $resultado['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($resultado['body'])) {
        http_response_code($resultado['status']);
        echo json_encode(['erro' => 'Resposta vazia do servidor', 'status' => $resultado['status']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decodedBody = json_decode($resultado['body'], true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(['erro' => 'Erro ao decodificar JSON: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($isPlaca && $resultado['status'] == 200) {
        if (isset($decodedBody['ufJurisdicao']) && !empty($decodedBody['ufJurisdicao'])) {
            $placa = $input;
            $uf = $decodedBody['ufJurisdicao'];
            $endpointDebito = "https://radar.serpro.gov.br/core-rest/gip-rest/veiculos/$placa/ufJurisdicao/$uf/debito/";
            
            $resultadoDebito = realizarConsulta($endpointDebito, trim($token));
            
            if ($resultadoDebito['status'] == 403 || $resultadoDebito['status'] == 401) {
                $novoToken = obterNovoToken();
                if ($novoToken) {
                    $resultadoDebito = realizarConsulta($endpointDebito, $novoToken);
                }
            }
            
            if ($resultadoDebito['status'] == 200 && !empty($resultadoDebito['body'])) {
                $dadosDebito = json_decode($resultadoDebito['body'], true);
                if (json_last_error() === JSON_ERROR_NONE && $dadosDebito) {
                    $decodedBody['debitos'] = [
                        'ipva' => [
                            'valor' => $dadosDebito['valorDebitoIPVA'] ?? 0.00,
                            'codigo' => $dadosDebito['codigoDebitoIpvaLicenc'] ?? 0
                        ],
                        'licenciamento' => [
                            'valor' => $dadosDebito['valorDebitoLicenciamento'] ?? 0.00
                        ],
                        'multas' => [
                            'valor' => $dadosDebito['valorDebitoMultas'] ?? 0.00,
                            'codigo' => $dadosDebito['codigoDebitoMultas'] ?? 0,
                            'indicadorMultaRenainf' => $dadosDebito['indicadorMultaRenainf'] ?? '0'
                        ],
                        'dpvat' => [
                            'valor' => $dadosDebito['valorDebitoDPVAT'] ?? 0.00
                        ],
                        'total' => round(($dadosDebito['valorDebitoIPVA'] ?? 0.00) + 
                                  ($dadosDebito['valorDebitoLicenciamento'] ?? 0.00) + 
                                  ($dadosDebito['valorDebitoMultas'] ?? 0.00) + 
                                  ($dadosDebito['valorDebitoDPVAT'] ?? 0.00), 2),
                        'situacao_veiculo' => $dadosDebito['tipoSituacaoVeiculo'] ?? null,
                        'indicadores' => [
                            'comunicacao_venda' => $dadosDebito['indicadorComunicacaoVenda'] ?? '0',
                            'emplacamento_eletronico' => $dadosDebito['indicadorEmplacamentoEletrônico'] ?? '0'
                        ],
                        'chassi' => $dadosDebito['codigoIdentificacaoVeiculo'] ?? null,
                        'capacidade_passageiros' => $dadosDebito['capacidadePassageiros'] ?? 0,
                        'data_atualizacao' => formatarData($dadosDebito['dataUltimaAtualizacao'] ?? null)
                    ];
                } else {
                    $decodedBody['debitos'] = ['erro' => 'Falha ao processar dados de debito'];
                }
            } else {
                $decodedBody['debitos'] = ['erro' => 'Nao foi possivel buscar debitos', 'status' => $resultadoDebito['status']];
            }
            
            if (isset($decodedBody['codigoTipoProprietario']) && isset($decodedBody['numeroIdentificacaoProprietario']) && isset($decodedBody['codigoRenavam'])) {
                $codigoTipoProprietario = $decodedBody['codigoTipoProprietario'];
                $cpfCnpj = $decodedBody['numeroIdentificacaoProprietario'];
                $renavam = $decodedBody['codigoRenavam'];
                
                $endpointComunicacaoVenda = "https://radar.serpro.gov.br/core-rest/gip-rest/veiculos/comunicacao-venda?codigoTipoProprietario=$codigoTipoProprietario&cpfCnpj=$cpfCnpj&placa=$placa&renavam=$renavam";
                
                $resultadoComunicacao = realizarConsulta($endpointComunicacaoVenda, trim($token));
                
                if ($resultadoComunicacao['status'] == 403 || $resultadoComunicacao['status'] == 401) {
                    $novoToken = obterNovoToken();
                    if ($novoToken) {
                        $resultadoComunicacao = realizarConsulta($endpointComunicacaoVenda, $novoToken);
                    }
                }
                
                if ($resultadoComunicacao['status'] == 200 && !empty($resultadoComunicacao['body'])) {
                    $dadosComunicacao = json_decode($resultadoComunicacao['body'], true);
                    if (json_last_error() === JSON_ERROR_NONE && $dadosComunicacao) {
                        $temComunicacao = !empty($dadosComunicacao['dataVenda']) || !empty($dadosComunicacao['nomeComprador']);
                        
                        if ($temComunicacao) {
                            $decodedBody['comunicacao_venda'] = [
                                'placa' => $dadosComunicacao['placa'],
                                'renavam' => $dadosComunicacao['renavam'],
                                'cpf_cnpj_proprietario' => $dadosComunicacao['numeroIdentificacaoProprietario'],
                                'data_venda' => formatarData($dadosComunicacao['dataVenda']),
                                'data_registro_venda' => formatarData($dadosComunicacao['dataRegistroVenda']),
                                'cpf_cnpj_comprador' => $dadosComunicacao['numeroIdentificacaoComprador'],
                                'nome_comprador' => $dadosComunicacao['nomeComprador'],
                                'tipo_identificacao_comprador' => $dadosComunicacao['tipoIdentificacaoComprador'],
                                'tem_comunicacao' => true
                            ];
                        } else {
                            $decodedBody['comunicacao_venda'] = [
                                'tem_comunicacao' => false,
                                'mensagem' => 'Nenhuma comunicacao de venda encontrada'
                            ];
                        }
                    } else {
                        $decodedBody['comunicacao_venda'] = ['erro' => 'Falha ao processar dados de comunicacao de venda'];
                    }
                } else {
                    $decodedBody['comunicacao_venda'] = ['erro' => 'Nao foi possivel buscar comunicacao de venda'];
                }
            } else {
                $decodedBody['comunicacao_venda'] = ['erro' => 'Dados insuficientes para consulta'];
            }
        } else {
            $decodedBody['debitos'] = ['erro' => 'UF nao encontrada na resposta da API'];
        }
    }

    http_response_code($resultado['status']);
    echo json_encode($decodedBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'erro' => 'Erro interno no servidor',
        'mensagem' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>