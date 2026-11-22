package org.sliid.admin

import android.app.Application
import android.content.Intent
import androidx.core.content.FileProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.launch
import kotlinx.coroutines.tasks.await
import org.json.JSONObject
import org.sliid.admin.data.*

data class AdminUiState(
    val loggedIn: Boolean = false,
    val loading: Boolean = false,
    val error: String? = null,
    val message: String? = null,
    val adminName: String = "Administrator",
    val counts: DashboardCounts = DashboardCounts(),
    val bookings: List<BookingSummary> = emptyList(),
    val booking: BookingDetail? = null,
    val ticket: TicketDetails? = null,
)

class MainViewModel(application: Application) : AndroidViewModel(application) {
    private val session = SessionManager(application)
    private val api = ApiClient(application)
    var state by mutableStateOf(AdminUiState(loggedIn = session.token != null, adminName = session.adminName))
        private set

    init { if (state.loggedIn) { loadDashboard(); registerNotifications() } }
    private fun begin() { state = state.copy(loading=true,error=null,message=null) }
    private fun fail(error: Throwable) { if (error is ApiException && error.status == 401) session.clear(); state = state.copy(loading=false,error=error.message ?: "Request failed",loggedIn=session.token!=null) }

    fun login(email: String, password: String) = viewModelScope.launch {
        begin(); runCatching { api.login(email,password) }.onSuccess {
            state = state.copy(loggedIn=true,loading=false,adminName=session.adminName); loadDashboard(); registerNotifications()
        }.onFailure(::fail)
    }
    fun loadDashboard() = viewModelScope.launch {
        begin(); runCatching { api.dashboard() }.onSuccess { json ->
            val c=json.getJSONObject("counts")
            val recent=json.getJSONArray("recent_bookings")
            state=state.copy(loading=false,counts=DashboardCounts(c.optInt("pending_receipts"),c.optInt("confirmed_bookings"),c.optInt("tickets_issued"),c.optInt("checked_in_today")),bookings=(0 until recent.length()).map{recent.getJSONObject(it).toBookingSummary()})
        }.onFailure(::fail)
    }
    fun loadBookings(status: String = "") = viewModelScope.launch {
        begin(); runCatching { api.bookings(status) }.onSuccess { json ->
            val rows=json.getJSONArray("data")
            state=state.copy(loading=false,bookings=(0 until rows.length()).map{rows.getJSONObject(it).toBookingSummary()})
        }.onFailure(::fail)
    }
    fun loadBooking(id: Int) = viewModelScope.launch {
        begin(); runCatching { api.booking(id) }.onSuccess { json ->
            val b=json.getJSONObject("booking"); val rows=json.getJSONArray("participants")
            state=state.copy(loading=false,booking=BookingDetail(
                id=b.getInt("id"),reference=b.getString("reference"),purchaser=b.getString("purchaser_name"),email=b.getString("purchaser_email"),phone=b.getString("purchaser_phone"),event=b.getString("event_title"),venue=b.optString("venue"),date=b.optString("starts_at"),total="${b.optString("currency")} ${b.optDouble("subtotal").asMoney()}",status=b.getString("status"),receiptAvailable=b.optBoolean("receipt_available"),notes=b.optString("admin_notes"),participants=(0 until rows.length()).map{pIndex-> val p=rows.getJSONObject(pIndex); Participant(p.getString("name"),p.getString("email"),p.getString("attendee_type"),p.optNullable("nic"),p.optNullable("membership_number"),p.optNullable("ticket_number"))}
            ))
        }.onFailure(::fail)
    }
    fun validateTicket(value: String) = viewModelScope.launch {
        begin(); runCatching { api.ticket(value) }.onSuccess { json -> state=state.copy(loading=false,ticket=json.getJSONObject("ticket").toTicket(value)) }.onFailure(::fail)
    }
    fun confirmCheckIn() = viewModelScope.launch {
        val raw=state.ticket?.rawValue ?: return@launch; begin(); runCatching { api.checkIn(raw) }.onSuccess { json -> state=state.copy(loading=false,ticket=json.getJSONObject("ticket").toTicket(raw),message="Entrance check-in confirmed") }.onFailure(::fail)
    }
    fun updateBooking(status: String, notes: String) = viewModelScope.launch {
        val id=state.booking?.id ?: return@launch; begin(); runCatching { api.updateBooking(id,status,notes) }.onSuccess { state=state.copy(loading=false,message=it.optString("message")); loadBooking(id) }.onFailure(::fail)
    }
    fun openReceipt() = viewModelScope.launch {
        val id=state.booking?.id ?: return@launch; begin(); runCatching { api.downloadReceipt(id,getApplication<Application>().cacheDir) }.onSuccess { file ->
            val app=getApplication<Application>(); val uri=FileProvider.getUriForFile(app,"${app.packageName}.files",file)
            app.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(uri,"application/pdf").addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
            state=state.copy(loading=false)
        }.onFailure(::fail)
    }
    fun clearTicket() { state=state.copy(ticket=null,error=null,message=null) }
    fun clearBooking() { state=state.copy(booking=null,error=null,message=null) }
    fun logout()=viewModelScope.launch { api.logout(); state=AdminUiState() }
    private fun registerNotifications()=viewModelScope.launch { runCatching { api.registerDevice(FirebaseMessaging.getInstance().token.await()) } }

    private fun JSONObject.toBookingSummary()=BookingSummary(getInt("id"),getString("reference"),getString("purchaser_name"),optInt("seats"),"${optString("currency")} ${optDouble("subtotal").asMoney()}",getString("status"),getString("event_title"),optString("created_at"))
    private fun JSONObject.toTicket(raw: String): TicketDetails { val a=getJSONObject("attendee"); val e=getJSONObject("event"); return TicketDetails(raw,getString("ticket_number"),a.getString("name"),a.getString("type"),if(a.getString("type")=="member")a.optString("membership_number","—")else a.optString("nic","—"),e.getString("title"),e.optString("venue"),e.optString("starts_at"),getString("status"),getBoolean("valid"),getBoolean("already_checked_in"),optNullable("checked_in_at")) }
    private fun JSONObject.optNullable(key:String)=if(isNull(key))null else optString(key).takeIf{it.isNotBlank()}
    private fun Double.asMoney()=String.format("%,.2f",this)
}
