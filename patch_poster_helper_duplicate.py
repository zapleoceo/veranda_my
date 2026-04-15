import re

with open('src/classes/PosterReservationHelper.php', 'r') as f:
    content = f.read()

# Let's see what PosterAPI methods exist. The only one used for getting reservations is incomingOrders.getReservations
# In reservations.php it's:
# $api->request('incomingOrders.getReservations', ['timezone' => 'client'], 'GET');

# So before creating, we should check if there's already a reservation with the same:
# 1. date_reservation (or within some range, but we can check exact or comment)
# The easiest way to check is to find reservations for the same table and date, or phone, or name.
# Or even check if a reservation with the comment containing "(Site #$reservationId)" already exists!
# But what if they changed the comment? We can just fetch all reservations for that day and check if one has the comment with our Site #ID.
