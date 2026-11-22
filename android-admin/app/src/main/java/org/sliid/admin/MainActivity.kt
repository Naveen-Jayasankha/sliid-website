package org.sliid.admin

import android.Manifest
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import org.sliid.admin.data.BookingDetail
import org.sliid.admin.data.BookingSummary
import org.sliid.admin.data.TicketDetails
import org.sliid.admin.scanner.TicketScanner

private val Navy=Color(0xFF071C29)
private val Gold=Color(0xFFB58C45)
private val Paper=Color(0xFFF4F1EA)
private val Success=Color(0xFF157347)
private val Danger=Color(0xFFB3261E)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { SliidTheme { val vm:MainViewModel=viewModel(); NotificationPermission(); SliidAdmin(vm,intent.getStringExtra("destination")) } }
    }
}

@Composable private fun NotificationPermission(){if(Build.VERSION.SDK_INT>=33){val launcher=rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()){};LaunchedEffect(Unit){launcher.launch(Manifest.permission.POST_NOTIFICATIONS)}}}

@Composable
private fun SliidTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = Navy,
            secondary = Gold,
            background = Paper,
            surface = Color.White,
            error = Danger
        ),
        typography = Typography(),
        content = content
    )
}

private enum class AdminTab(val label:String){Dashboard("Overview"),Bookings("Bookings"),Scan("Scan")}

@Composable
@OptIn(ExperimentalMaterial3Api::class)
private fun SliidAdmin(vm:MainViewModel,initialDestination:String?){
    val state=vm.state
    if(!state.loggedIn){LoginScreen(state.loading,state.error,vm::login);return}
    var tab by remember{mutableStateOf(if(initialDestination=="bookings")AdminTab.Bookings else AdminTab.Dashboard)}
    Scaffold(
        containerColor=Paper,
        topBar={TopAppBar(title={Column{Text("SLIID",fontSize=25.sp,fontWeight=FontWeight.Bold);Text(state.adminName,fontSize=11.sp)}},colors=TopAppBarDefaults.topAppBarColors(containerColor=Navy,titleContentColor=Color.White),actions={IconButton(onClick={when(tab){AdminTab.Dashboard->vm.loadDashboard();AdminTab.Bookings->vm.loadBookings();AdminTab.Scan->vm.clearTicket()}}){Icon(Icons.Outlined.Refresh,"Refresh",tint=Color.White)};IconButton(onClick=vm::logout){Icon(Icons.Outlined.Logout,"Sign out",tint=Color.White)}})},
        bottomBar={NavigationBar(containerColor=Color.White){AdminTab.entries.forEach{item->NavigationBarItem(selected=tab==item,onClick={tab=item;if(item==AdminTab.Dashboard)vm.loadDashboard();if(item==AdminTab.Bookings)vm.loadBookings();if(item==AdminTab.Scan)vm.clearTicket()},icon={Icon(when(item){AdminTab.Dashboard->Icons.Outlined.Dashboard;AdminTab.Bookings->Icons.Outlined.ReceiptLong;AdminTab.Scan->Icons.Outlined.QrCodeScanner},item.label)},label={Text(item.label)})}}}
    ){padding->Box(Modifier.padding(padding).fillMaxSize()){
        when{state.booking!=null->BookingDetailScreen(state.booking,state.loading,vm::clearBooking,vm::openReceipt,vm::updateBooking);tab==AdminTab.Dashboard->DashboardScreen(state,vm::loadBooking);tab==AdminTab.Bookings->BookingsScreen(state.bookings,state.loading,vm::loadBooking);else->ScanScreen(state.ticket,state.loading,state.error,state.message,vm::validateTicket,vm::confirmCheckIn,vm::clearTicket)}
        if(state.loading&&state.booking==null&&tab!=AdminTab.Scan)LinearProgressIndicator(Modifier.fillMaxWidth().align(Alignment.TopCenter),color=Gold)
    }}
}

@Composable
private fun LoginScreen(loading:Boolean,error:String?,onLogin:(String,String)->Unit){var email by remember{mutableStateOf("")};var password by remember{mutableStateOf("")};Box(Modifier.fillMaxSize().background(Navy).padding(28.dp),contentAlignment=Alignment.Center){Card(shape=RoundedCornerShape(24.dp)){Column(Modifier.padding(28.dp).widthIn(max=420.dp)){Text("SLIID",fontSize=40.sp,fontWeight=FontWeight.Bold,color=Navy);Text("Administration mobile app",color=Gold);Spacer(Modifier.height(28.dp));OutlinedTextField(email,{email=it},Modifier.fillMaxWidth(),label={Text("Email")},singleLine=true);Spacer(Modifier.height(12.dp));OutlinedTextField(password,{password=it},Modifier.fillMaxWidth(),label={Text("Password")},singleLine=true,visualTransformation=PasswordVisualTransformation());error?.let{Text(it,color=Danger,modifier=Modifier.padding(top=12.dp))};Button(onClick={onLogin(email.trim(),password)},enabled=!loading&&email.isNotBlank()&&password.isNotBlank(),modifier=Modifier.fillMaxWidth().padding(top=22.dp),colors=ButtonDefaults.buttonColors(containerColor=Gold)){if(loading)CircularProgressIndicator(Modifier.size(20.dp),strokeWidth=2.dp,color=Color.White)else Text("Sign in")}}}}
}

@Composable
private fun DashboardScreen(state:AdminUiState,onBooking:(Int)->Unit){LazyColumn(Modifier.fillMaxSize().padding(18.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){item{Text("Administration overview",fontSize=27.sp,fontWeight=FontWeight.Bold);Text("Live event booking and entrance status",color=Color.Gray)};item{Row(horizontalArrangement=Arrangement.spacedBy(10.dp)){Metric("Receipts",state.counts.pendingReceipts,Gold,Modifier.weight(1f));Metric("Checked in",state.counts.checkedInToday,Success,Modifier.weight(1f))}};item{Row(horizontalArrangement=Arrangement.spacedBy(10.dp)){Metric("Confirmed",state.counts.confirmedBookings,Navy,Modifier.weight(1f));Metric("Tickets",state.counts.ticketsIssued,Navy,Modifier.weight(1f))}};item{Text("Recent bookings",fontSize=20.sp,fontWeight=FontWeight.Bold,modifier=Modifier.padding(top=12.dp))};items(state.bookings,key={it.id}){BookingRow(it,onBooking)}}}

@Composable private fun Metric(label:String,value:Int,color:Color,modifier:Modifier=Modifier){Card(modifier){Column(Modifier.padding(18.dp)){Text(value.toString(),fontSize=31.sp,fontWeight=FontWeight.Bold,color=color);Text(label,color=Color.Gray)}}}

@Composable
private fun BookingsScreen(bookings:List<BookingSummary>,loading:Boolean,onBooking:(Int)->Unit){LazyColumn(Modifier.fillMaxSize().padding(18.dp),verticalArrangement=Arrangement.spacedBy(10.dp)){item{Text("Event bookings",fontSize=27.sp,fontWeight=FontWeight.Bold);Text("Review receipts and confirm tickets",color=Color.Gray)};if(!loading&&bookings.isEmpty())item{Text("No bookings found.",modifier=Modifier.padding(28.dp))};items(bookings,key={it.id}){BookingRow(it,onBooking)}}}

@Composable private fun BookingRow(item:BookingSummary,onBooking:(Int)->Unit){Card(Modifier.fillMaxWidth().clickable{onBooking(item.id)}){Column(Modifier.padding(16.dp)){Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text(item.reference,fontWeight=FontWeight.Bold,color=Gold);Status(item.status)};Text(item.event,fontSize=17.sp,fontWeight=FontWeight.SemiBold,modifier=Modifier.padding(top=7.dp));Text("${item.purchaser} · ${item.seats} seat(s)",color=Color.Gray);Text(item.total,fontWeight=FontWeight.Bold,modifier=Modifier.padding(top=6.dp))}}}

@Composable
private fun BookingDetailScreen(item:BookingDetail,loading:Boolean,onBack:()->Unit,onReceipt:()->Unit,onStatus:(String,String)->Unit){var notes by remember(item.id){mutableStateOf(item.notes)};LazyColumn(Modifier.fillMaxSize().padding(18.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){item{TextButton(onClick=onBack){Icon(Icons.Outlined.ArrowBack,null);Text("Bookings")};Text(item.event,fontSize=25.sp,fontWeight=FontWeight.Bold);Text(item.reference,color=Gold,fontWeight=FontWeight.Bold);Status(item.status)};item{Card{Column(Modifier.padding(18.dp)){Detail("Purchaser",item.purchaser);Detail("Email",item.email);Detail("Phone",item.phone);Detail("Date",item.date);Detail("Venue",item.venue);Detail("Total",item.total)}}};if(item.receiptAvailable)item{OutlinedButton(onClick=onReceipt,enabled=!loading,modifier=Modifier.fillMaxWidth()){Icon(Icons.Outlined.PictureAsPdf,null);Spacer(Modifier.width(8.dp));Text("Open bank receipt PDF")}};item{Text("Participants",fontSize=19.sp,fontWeight=FontWeight.Bold)};items(item.participants){p->Card{Column(Modifier.padding(15.dp)){Text(p.name,fontWeight=FontWeight.Bold);Text("${p.email} · ${p.type.replace('_',' ')}",color=Color.Gray);p.nic?.let{Text("NIC $it")};p.membership?.let{Text("Member $it")};p.ticketNumber?.let{Text(it,color=Gold,fontWeight=FontWeight.Bold)}}}};item{OutlinedTextField(notes,{notes=it},Modifier.fillMaxWidth(),label={Text("Verification notes")},minLines=2);Row(Modifier.fillMaxWidth().padding(top=10.dp),horizontalArrangement=Arrangement.spacedBy(10.dp)){OutlinedButton(onClick={onStatus("rejected",notes)},enabled=!loading,modifier=Modifier.weight(1f),colors=ButtonDefaults.outlinedButtonColors(contentColor=Danger)){Text("Reject")};Button(onClick={onStatus("confirmed",notes)},enabled=!loading,modifier=Modifier.weight(1f),colors=ButtonDefaults.buttonColors(containerColor=Success)){Text("Approve & ticket")}};Spacer(Modifier.height(18.dp))}}
}

@Composable private fun Detail(label:String,value:String){Text(label.uppercase(),fontSize=10.sp,color=Color.Gray);Text(value.ifBlank{"—"},fontWeight=FontWeight.SemiBold,modifier=Modifier.padding(bottom=10.dp))}

@Composable
private fun ScanScreen(ticket:TicketDetails?,loading:Boolean,error:String?,message:String?,onScan:(String)->Unit,onCheckIn:()->Unit,onAgain:()->Unit){if(ticket!=null){TicketResult(ticket,loading,message,onCheckIn,onAgain);return};var manual by remember{mutableStateOf("")};Column(Modifier.fillMaxSize()){Row(Modifier.padding(12.dp),verticalAlignment=Alignment.CenterVertically){OutlinedTextField(manual,{manual=it},Modifier.weight(1f),label={Text("Ticket number")},singleLine=true);IconButton(onClick={if(manual.isNotBlank()){onScan(manual)}}){Icon(Icons.Outlined.Search,"Validate")}};error?.let{Text(it,color=Danger,modifier=Modifier.padding(horizontal=16.dp,vertical=6.dp))};Box(Modifier.weight(1f)){if(loading)CircularProgressIndicator(Modifier.align(Alignment.Center),color=Gold)else TicketScanner(onCode=onScan)}}}

@Composable
private fun TicketResult(ticket:TicketDetails,loading:Boolean,message:String?,onCheckIn:()->Unit,onAgain:()->Unit){LazyColumn(Modifier.fillMaxSize().padding(20.dp),horizontalAlignment=Alignment.CenterHorizontally,verticalArrangement=Arrangement.spacedBy(14.dp)){item{Icon(if(ticket.valid)Icons.Outlined.CheckCircle else Icons.Outlined.Cancel,null,Modifier.size(82.dp),tint=if(ticket.valid)Success else Danger);Text(if(ticket.checkedIn)"Already checked in" else if(ticket.valid)"Valid e-ticket" else "Invalid ticket",fontSize=24.sp,fontWeight=FontWeight.Bold);Text(ticket.number,color=Gold,fontWeight=FontWeight.Bold);message?.let{Text(it,color=Success)}};item{Card(Modifier.fillMaxWidth()){Column(Modifier.padding(18.dp)){Text(ticket.attendee,fontSize=25.sp,fontWeight=FontWeight.Bold);Detail("Type",ticket.attendeeType.replace('_',' '));Detail(if(ticket.attendeeType=="member")"Membership number" else "NIC",ticket.identity);Divider();Detail("Event",ticket.event);Detail("Date and time",ticket.date);Detail("Venue",ticket.venue);ticket.checkedInAt?.let{Detail("Checked in",it)}}}};item{if(ticket.valid&&!ticket.checkedIn)Button(onClick=onCheckIn,enabled=!loading,modifier=Modifier.fillMaxWidth(),colors=ButtonDefaults.buttonColors(containerColor=Success)){Text("Confirm entrance check-in")};OutlinedButton(onClick=onAgain,modifier=Modifier.fillMaxWidth()){Text("Scan another ticket")}}}}

@Composable private fun Status(value:String){val color=when(value){"confirmed"->Success;"rejected","cancelled"->Danger;else->Gold};Surface(color=color.copy(alpha=.12f),shape=RoundedCornerShape(20.dp)){Text(value.replace('_',' ').uppercase(),color=color,fontSize=10.sp,fontWeight=FontWeight.Bold,modifier=Modifier.padding(horizontal=10.dp,vertical=6.dp))}}
