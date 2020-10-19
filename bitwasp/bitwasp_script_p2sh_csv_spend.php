<?php 
use BitWasp\Buffertools\Buffer;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Address\PayToPubKeyHashAddress;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Factory\PrivateKeyFactory;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\Factory\SignData;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Bitcoin\Script\Classifier\OutputClassifier;
use BitWasp\Bitcoin\Script\ScriptType;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\P2shScript;
use BitWasp\Bitcoin\Signature\TransactionSignature;

include_once "../libraries/vendor/autoload.php";
$no_of_inputs = 10;
$no_of_outputs = 10;

if ($_SERVER['REQUEST_METHOD'] == 'GET' AND $_GET['ajax'] == '1') {
	$data = [];
	if (!ctype_xdigit($_GET['utx'])) {
		$data = ["error"=>"UTX must be hex."];
	} else {
		$utxHex = $_GET['utx'];
		$utx = TransactionFactory::fromHex($utxHex);
		$outputs = $utx->getOutputs();
		
		$data['txHash'] = $utx->getTxHash()->flip()->getHex();//swap endianess
		$data['outputs'] = [];
		if (@count($outputs)) {
			foreach($outputs as $k=>$output) {
				if ($k < 10) { //limit to retrieve max 10 inputs only
				
					$decodeScript = (new OutputClassifier())->decode($output->getScript());
						
					if ($decodeScript->getType() != ScriptType::P2SH) {
						$data = ["error"=>"Load fail! Your unspent transaction output (utxo) can only contain P2SH ScriptPubKey."];
						break;
					}
					$data['outputs'][] = ["amount"=>$output->getValue(), "n"=>$k, "scriptPubKey"=>$output->getScript()->getHex()];
				}
			}
		}
	}
	
	die(json_encode($data));
}

include_once("html_iframe_header.php");
?>
<div class='vertical-line-yellow'>
	<h6 class="mt-3">To success spend of OP_CSV, following requirements must meet:</h6>
	
	<ul>
		<li>Transaction must be version 2.</li>
		<li>If relative lock-time flag detected in input's nSequence, then UTXO's block MTP must satisfy &lt;  chain's MTP.</li>	
		<li>if block height detected in input's nSequence, then UTXO's block height must satisfy &lt; chain's block height + 1.</li>
		<li>&lt;expiry time&gt; in redeem script must &lt;= nSequence.</li>
	</ul>
</div>
<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	try {
		$networkClass   = $_POST['network'];
		Bitcoin::setNetwork(NetworkFactory::$networkClass());
		
		$network        = Bitcoin::getNetwork();
		$ecAdapter      = Bitcoin::getEcAdapter();
		$privKeyFactory = new PrivateKeyFactory();
		
		$addrCreator = new AddressCreator();
		
		$spendTx = TransactionFactory::build();
		$spendTx->version(2);//important
		$signItems = [];
		
		if (!strlen($_POST['utx']) or !ctype_xdigit($_POST['utx'])) {                    
			throw new Exception("UTX must be hex.");
		} 
		
		if (!is_numeric($_POST['no_of_inputs']) OR !is_numeric($_POST['no_of_outputs'])) {
			throw new Exception("Error in 'no_of_inputs' or 'no_of_outputs'.");
		}
		
		$utxHex = $_POST['utx'];
		$utx = TransactionFactory::fromHex($utxHex);    
		$utxos = $utx->getOutputs();	
		
		foreach($utxos as $k=>$utxo) {
			$privateKey = $privKeyFactory->fromHexCompressed($_POST["privkey_" . ($k+1)]);
			$utxoScript = $utxo->getScript();
			$redeemScript = trim($_POST["redeemscript_" . ($k+1)]);
			$redeemScript = new P2shScript(ScriptFactory::fromHex($redeemScript));
			
			$timeParam = $redeemScript->getScriptParser()->getHumanReadable();
			$timeParam = strstr($timeParam, ' ', true);
			
			if (preg_match('/^OP_/', $timeParam)) {
				$timeParam = ltrim($timeParam, 'OP_');
				$timeParam = (int)$timeParam;
				$sequence = $timeParam;
			} else {
				$sequence = Buffer::hex($timeParam)->flip()->getInt();
			}
			
			$p2shScriptPubKey = ScriptFactory::scriptPubKey()->p2sh($redeemScript->getScriptHash()/*sha256*/);
			
			if ($utxoScript->getHex() != $p2shScriptPubKey->getHex()) {
				throw new Exception("Error in 'input#{$k}'. Redeem script is not matched to UTXO's ScriptPubKey.");
			}
			
			$signData = new SignData();
			$signData->p2sh($redeemScript);
			
			$signItems[] = [ $privateKey, $signData ];
			$spendTx = $spendTx->spendOutputFrom($utx, $k, null, $sequence);
		}
		
		foreach(range(1,$_POST['no_of_outputs']) as $this_output) {
			
			$address = trim($_POST["address_{$this_output}"]);
			$amount = trim($_POST["amount_{$this_output}"]);
			
			if (strlen($address)>0 AND strlen($amount)>0) {
				$recipient = $addrCreator->fromString($address);
				$spendTx = $spendTx->payToAddress($amount, $recipient);
			} else {
				throw new Exception("Error in 'output#{$this_output}'.");
			}
		}
		
		$thisTx = $spendTx->get();
		$finalTx = TransactionFactory::mutate($thisTx);
		$mutable = TransactionFactory::mutate($thisTx);
		$sigHashType = 1; //ALL
		foreach($signItems as $nIn=>$signItem) {
			$privateKey = $signItem[0];
			$signData   = $signItem[1];
			$input = $mutable->inputsMutator()[$nIn];
			$input->script($signData->getRedeemScript());
			$finalSignData = $mutable->done()->getHex() .  bin2hex(pack('V', $sigHashType));
			$finalSignData = hash('sha256', hex2bin(hash('sha256', hex2bin($finalSignData))));
			$txSig = new TransactionSignature($ecAdapter, $privateKey->sign(Buffer::hex($finalSignData)), $sigHashType);
			$derSigHex = $txSig->getSignature()->getBuffer()->getHex() . Buffer::int($sigHashType, 1)->getHex();
			
			$input = $finalTx->inputsMutator()[$nIn];
			$inputSig = ScriptFactory::sequence(
				[Buffer::hex($derSigHex),
				 Buffer::hex($privateKey->getPublicKey()->getHex()),
				 Buffer::hex($signData->getRedeemScript()->getHex())
				]);
			$input->script($inputSig);
		}
		
	?>
		<div class="alert alert-success">
			<h6 class="mt-3">Final TX Hex</h6>
			
			<textarea class="form-control" rows="5" id="comment" readonly><?php echo $finalTx->done()->getHex();?></textarea>
		</div>
	<?php
	
	} catch (Exception $e) {
		$errmsg .= "Problem found. " . $e->getMessage();

	}
}

if ($errmsg) {
?>
	<div class="alert alert-danger">
		<strong>Error!</strong> <?php echo $errmsg?>
	</div>
<?php
}
?>
<form action='?tab=form2_tabitem3#hashtag2' method='post'>
	<div class="form-group">
		<label for="network">Network: </label>
		<select name="network" id="network" class="form-control">
			<?php
			$networks = get_class_methods(new NetworkFactory());
			foreach($networks as $network) {
				echo "<option value='{$network}'".($network == $_POST['network'] ? " selected": "").">{$network}</option>";
			}
			?>
		</select>
	</div>
	<div class="form-group">
		<label for="utx">Unspent Transaction (Hex):</label>
		
		<div class="input-group mb-3">
			<input class="form-control" type='text' name='utx' id='utx' value='<?php echo $_POST['utx']?>'>
			<div class="input-group-append">
				<input class="btn btn-success" type="button" id="form2_tabitem3_load" value="Load" onclick="
				var self = $(this);
				self.val('......'); 
				
				var form = self.closest('form');
				var allInputDivs = form.find('div[id^=row_input_]');
				var allInputs = $( ':input', allInputDivs );
				allInputs.val('');
				allInputDivs.hide();
				$('select#no_of_inputs',form).empty();
				$('span#total_inputs',form).html('0');
				$.ajax({
					url: '?ajax=1&utx=' + $('input#utx',form).val(), 
					
					error:function() {
						
					},
					
					success:function(result){
						try {
							j = eval('(' + result + ')');
							
							if ('error' in j && j.error.length>0) {
								var error = true;
							} else {
								var error = false;
							}
							
							if (!error) {
								var txHash = j.txHash;
								var outputs = j.outputs;
								var totalInputs = 0;
								
								if (outputs.length > 0) {
									
									
									$('select#no_of_inputs',form).prepend('<option value=\''+outputs.length+'\' selected=\'selected\'>'+outputs.length+'</option>');
									
									var x;    
									for (x in outputs) {
										var divid = parseInt(x) + 1;
										var amount = parseInt(outputs[x].amount);
										totalInputs += amount;
										
										$('div#row_input_' + divid             ,form).show();
										$('input[name=utxo_hash_' + divid+']'  ,form).val(txHash);
										$('input[name=utxo_n_' + divid+']'     ,form).val(outputs[x].n);
										$('input[name=utxo_script_' + divid+']',form).val(outputs[x].scriptPubKey);
										$('input[name=utxo_amount_' + divid+']',form).val(amount);
										$('input[name=privkey_'+divid+']'      ,form).removeAttr('readonly');
									}
								}
								
								$('span#total_inputs',form).html(totalInputs);
							} else {
								alert(j.error);
							}
						} catch(e) {
							alert('Invalid Json Format.');
						}
					},
					complete:function() {
						self.val('Load');
					}
				});
				"/>
			</div>
		</div>
	</div>
	<div class="row">
		<div class="col-sm-6">
			
			<div class="form-row">
				<div class="form-group col">
					<label for="no_of_inputs">Inputs: <i>Max 10 inputs only. Please load them from UTX above.</i></label> 
					
					<div class="row">
						<div class="col-sm-2">
							<select class="form-control" id="no_of_inputs" name='no_of_inputs' style='width:auto;' onchange="
							var self = $(this);
							var thisvalue = self.val();
							var form = self.closest('form');
							$('div[id^=row_input_]',form).hide();
							for(var i=1; i<= thisvalue; i++) { 
								$('div[id=row_input_'+  i + ']',form).show();
							}
							" readonly>
								<?php
								$optionval = $_POST['no_of_inputs']?$_POST['no_of_inputs'] : 0;
								
								if ($optionval > 0) {
								?>
								<option value='<?php echo $optionval?>'><?php echo $optionval?></option>
								<?php
								}
								?>
							</select>
						</div>
						
						<div class="col-sm-10">
							Total Sathoshi: 
							<span id='total_inputs'>
							<?php echo 
							array_reduce(
								array_keys($_POST),
								function($carry, $key)  { 
									if (preg_match('/^utxo_amount_/', $key)) {
										return $carry + (int)$_POST[$key];
									} else {                    
										return $carry;
									}
								}, 0
							);?>
							</span>
						</div>
					</div>
				</div>
			</div>
			
			<?php
			$selected_n_inputs = is_numeric($_POST['no_of_inputs']) ? $_POST['no_of_inputs'] : 0;
			
			foreach(range(1,$no_of_inputs) as $this_input) {
			?>
			
				<div class="form-row" id='row_input_<?php echo $this_input?>' style="<?php echo ($this_input > $selected_n_inputs) ? "display:none" : "display:;"?>">
					<input type='hidden' name='utxo_amount_<?php echo $this_input?>' value='<?php echo $_POST["utxo_amount_{$this_input}"]?>'/>
					
					<div class="form-group  col-sm-1">
						#<?php echo $this_input?> 
					</div>
					<div class="form-group  col-sm-3">    
						<input class="form-control" title="UTXO Tx Hash" placeholder='UTXO Tx Hash' type='text' name='utxo_hash_<?php echo $this_input?>' value='<?php echo $_POST["utxo_hash_{$this_input}"]?>' readonly>
					</div>
					<div class="form-group  col-sm-1">
						<input class="form-control" title="UTXO N Output" placeholder='N' type='text' name='utxo_n_<?php echo $this_input?>' value='<?php echo $_POST["utxo_n_{$this_input}"]?>' readonly>
					</div>
					<div class="form-group  col-sm-3">
						<input class="form-control" title="UTXO ScriptPubKey" placeholder='UTXO ScriptPubKey' type='text' name='utxo_script_<?php echo $this_input?>' value='<?php echo $_POST["utxo_script_{$this_input}"]?>' readonly>
					</div>
					<div class="form-group  col-sm-4">
						<input class="form-control" title="Private Key Hex, for signing purpose." placeholder='Private Key Hex' type='text' name='privkey_<?php echo $this_input?>' value='<?php echo $_POST["privkey_{$this_input}"]?>'>
					</div>
					
					<div class="form-group  col-sm-1" >
					
					</div>
					<div class="form-group  col-sm-11">
						<input class="form-control" placeholder='Redeem Script' type='text' name='redeemscript_<?php echo $this_input?>' value='<?php echo $_POST["redeemscript_{$this_input}"]?>'>
					</div>
				</div>
			<?php
			}
			?>
		</div>
		<div class="col-sm-6">
			<div class="form-row">
				<div class="form-group col">
					<label for="no_of_outputs">Outputs:</label> <select class="form-control" id="no_of_outputs" name='no_of_outputs' style='width:auto;' onchange="
					var self = $(this);
					var thisvalue = self.val();
					var form = self.closest('form');
					$('div[id^=row_output_]',form).hide();
					for(var i=1; i<= thisvalue; i++) { 
						$('div[id=row_output_'+  i + ']',form).show();
					}
					">
						<?php
						foreach(range(1,$no_of_outputs) as $this_output) {
							echo "<option value='{$this_output}'".($this_output == $_POST['no_of_outputs'] ? " selected": "").">{$this_output}</option>";
						}
						?>
					</select>
				</div>
			</div>
			<?php
			$selected_n_outputs = is_numeric($_POST['no_of_outputs']) ? $_POST['no_of_outputs'] : 1;
			
			
			foreach(range(1,$no_of_outputs) as $this_output) {
			?>
				<div class="form-row" id='row_output_<?php echo $this_output?>' style="<?php echo ($this_output > $selected_n_outputs) ? "display:none" : "display:;"?>">
					<div class="form-group col-sm-1">
						#<?php echo $this_output?> 
					</div>
					
					<div class="form-group col-sm-6">
						<input class="form-control" placeholder='Any Address' type='text' name='address_<?php echo $this_output?>' value='<?php echo $_POST["address_{$this_output}"]?>'>
					</div>
					<div class="form-group col-sm-5">
						<input class="form-control" placeholder='Amount' type='text' name='amount_<?php echo $this_output?>' value='<?php echo $_POST["amount_{$this_output}"]?>'>
					</div>
				</div>
	<?php
			}
	?>
		</div>
	</div>
	
	<input type='submit' class="btn btn-success btn-block"/>
</form>
<!-- The Modal -->
<div class="modal" id="myModal" data-backdrop="false">
	<div class="modal-dialog">
		<div class="modal-content">

			<!-- Modal Header -->
			<div class="modal-header">
				<h4 class="modal-title"><span id='network_display'></span>: Height & MTP</h4>
				<button type="button" class="close" data-dismiss="modal">&times;</button>
			</div>

			<!-- Modal body -->
			<div class="modal-body">
		
			</div>
		</div>
	</div>
</div>
<?php
include_once("html_iframe_footer.php");