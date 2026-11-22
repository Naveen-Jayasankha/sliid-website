package org.sliid.admin.scanner

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.CameraSelector
import androidx.camera.core.ExperimentalGetImage
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.QrCodeScanner
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import java.util.concurrent.Executors

@OptIn(ExperimentalGetImage::class)
@Composable
fun TicketScanner(onCode: (String) -> Unit) {
    val context=LocalContext.current
    val lifecycleOwner=LocalLifecycleOwner.current
    var granted by remember { mutableStateOf(ContextCompat.checkSelfPermission(context,Manifest.permission.CAMERA)==PackageManager.PERMISSION_GRANTED) }
    val permission=rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()){granted=it}
    LaunchedEffect(Unit){if(!granted)permission.launch(Manifest.permission.CAMERA)}
    if(!granted){Box(Modifier.fillMaxSize(),contentAlignment=Alignment.Center){Column(horizontalAlignment=Alignment.CenterHorizontally){Icon(Icons.Outlined.QrCodeScanner,null,Modifier.size(56.dp));Spacer(Modifier.height(12.dp));Text("Camera permission is required to scan e-tickets.");Button(onClick={permission.launch(Manifest.permission.CAMERA)}){Text("Allow camera")}}};return}

    var captured by remember { mutableStateOf(false) }
    val executor=remember{Executors.newSingleThreadExecutor()}
    DisposableEffect(Unit){onDispose{executor.shutdown()}}
    Box(Modifier.fillMaxSize().background(Color.Black)){
        AndroidView(factory={ctx->
            val view=PreviewView(ctx).apply{scaleType=PreviewView.ScaleType.FILL_CENTER}
            val providerFuture=ProcessCameraProvider.getInstance(ctx)
            providerFuture.addListener({
                val provider=providerFuture.get()
                val preview=Preview.Builder().build().also{it.setSurfaceProvider(view.surfaceProvider)}
                val scanner=BarcodeScanning.getClient(BarcodeScannerOptions.Builder().setBarcodeFormats(Barcode.FORMAT_QR_CODE).build())
                val analysis=ImageAnalysis.Builder().setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST).build()
                analysis.setAnalyzer(executor){proxy->
                    val media=proxy.image
                    if(media==null||captured){proxy.close();return@setAnalyzer}
                    scanner.process(InputImage.fromMediaImage(media,proxy.imageInfo.rotationDegrees)).addOnSuccessListener{codes->
                        codes.firstOrNull()?.rawValue?.takeIf{it.isNotBlank()}?.let{value->if(!captured){captured=true;onCode(value)}}
                    }.addOnCompleteListener{proxy.close()}
                }
                runCatching{provider.unbindAll();provider.bindToLifecycle(lifecycleOwner,CameraSelector.DEFAULT_BACK_CAMERA,preview,analysis)}
            },ContextCompat.getMainExecutor(ctx))
            view
        },modifier=Modifier.fillMaxSize())
        Box(Modifier.align(Alignment.Center).size(260.dp).background(Color.Transparent))
        Surface(Modifier.align(Alignment.BottomCenter).padding(22.dp),color=Color(0xCC071C29),shape=MaterialTheme.shapes.large){Text("Place the ticket QR inside the frame",color=Color.White,modifier=Modifier.padding(horizontal=22.dp,vertical=14.dp))}
    }
}
